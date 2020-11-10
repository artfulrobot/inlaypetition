<?php

namespace Civi\Inlay;

use Civi\Inlay\Type as InlayType;
use Civi\Api4\OptionValue;
use Civi\Inlay\ApiRequest;
use Civi;
use CRM_Inlaypetition_ExtensionUtil as E;
use CRM_Queue_Task;
use CRM_Queue_Service;

class Petition extends InlayType {

  public static $petitionActivityType = NULL;

  public static $typeName = 'Petition';

  /**
   * Cache so that when processing a set of queued signups we don't have to
   * load the Inlay instance for each time.
   *
   * Keyed by instance ID.
   */
  public static $instanceCache = [];

  public static $defaultConfig = [
    // Nb. the inlay's name is used for the petition activity subject.

    'publicTitle'      => '',
    'submitButtonText' => 'Sign',
    'smallprintHTML'   => NULL,
    //'phoneAsk'         => TRUE,
    'webThanksHTML'    => NULL,
    'target'           => NULL,
    'socials'          => ['twitter', 'facebook', 'email', 'whatsapp'],
    'tweet'            => '',
    'whatsappText'     => '',
  ];

  /**
   * Note: because of the way CRM.url works, you MUST put a ? before the #
   *
   * @var string
   */
  public static $editURLTemplate = 'civicrm/a?#/inlay/petition/{id}';

  /**
   * Sets the config ensuring it's valid.
   *
   * This implementation simply ensures all the defaults exist, and that no
   * other keys exist, but you could do other things, especially if you need to
   * coerce some old config into a new style.
   *
   * @param array $config
   *
   * @return \Civi\Inlay\Type (this)
   */
  public function setConfig(array $config) {
    $this->config = array_intersect_key($config + static::$defaultConfig, static::$defaultConfig);
  }

  /**
   * Generates data to be served with the Javascript application code bundle.
   *
   * @return array
   */
  public function getInitData() {

    $data = [
      // Name of global Javascript function used to boot this app.
      'init'             => 'inlayPetitionInit',
    ];
    foreach (['submitButtonText', 'publicTitle', 'smallprintHTML', 'webThanksHTML',
    ] as $_) {
      $data[$_] = $this->config[$_] ?? '';
    }

    // Nb. same logic as SignupA
    $data['socials'] = [];
    foreach ($this->config['socials'] as $social) {
      $_ = ['name' => $social];
      if ($social === 'twitter') {
        $_['tweet'] = $this->config['tweet'];
      }
      elseif ($social === 'whatsapp') {
        $_['whatsappText'] = $this->config['whatsappText'];
      }
      $data['socials'][] = $_;
    }

    // Count people signed up...
    $subject = $this->getName();
    $activityTypeID = $this->getActivityTypeID();

    $data['count'] = (int) \CRM_Core_DAO::singleValueQuery("
        SELECT COUNT(*)
        FROM civicrm_activity a
        INNER JOIN civicrm_activity_contact ac ON a.id = ac.activity_id AND ac.record_type_id = 3 /*target*/
        INNER JOIN civicrm_contact c ON ac.contact_id = c.id AND c.is_deleted = 0
        WHERE a.activity_type_id = %1 AND a.subject = %2
      ", [
        1 => [$activityTypeID, 'Integer'],
        2 => [$subject, 'String'],
      ]);
      Civi::log()->info("Count is $data[count] for petition inlay $subject");

    return $data;
  }

  /**
   * Find our activity type
   *
   * @todo optimise this, it's probly slow.
   */
  public static function getActivityTypeID() {
    if (!static::$petitionActivityType) {
      // Look it up.
      static::$petitionActivityType = OptionValue::get(FALSE)
        ->addSelect('value')
        ->addWhere('option_group_id:name', '=', 'activity_type')
        ->addWhere('name', '=', 'inlay_petition')
        ->execute()->first()['value'] ?? 0;
    }
    return static::$petitionActivityType;
  }

  /**
   * Process a request
   *
   * Request data is just key, value pairs from the form data. If it does not
   * have 'token' field then a token is generated and returned. Otherwise the
   * token is checked and processing continues.
   *
   * @param \Civi\Inlay\Request $request
   * @return array
   *
   * @throws \Civi\Inlay\ApiException;
   */
  public function processRequest(ApiRequest $request) {

    $data = $this->cleanupInput($request->getBody());

    if (empty($data['token'])) {
      // Unsigned request. Issue a token that will be valid in 5s time and lasts 2mins max.
      return ['token' => $this->getCSRFToken(['data' => $data, 'validFrom' => 5, 'validTo' => 120])];
    }

    // Defer processing the data to a queue. This speeds things up for the user
    // and avoids database deadlocks.
    $queue = static::getQueueService();

    // We have context that is not stored in $data, namely which Inlay Instance we are.
    // Store that now.
    $data['inlayID'] = $this->getID();

    $queue->createItem(new CRM_Queue_Task(
      ['Civi\\Inlay\\Petition', 'processQueueItem'], // callback
      [$data], // arguments
      "" // title
    ));

    // Optimistically return!
    return ['success' =>1];
  }

  /**
   * @return CRM_Queue_Service
   */
  public static function getQueueService() {
    return CRM_Queue_Service::singleton()->create([
      'type'  => 'Sql',
      'name'  => 'inlay-petition',
      'reset' => FALSE, // We do NOT want to delete an existing queue!
    ]);
  }
  /**
   * Process a queued submission.
   *
   * This is the callback for the queue runner.
   *
   * Nb. the data has already been validated.
   *
   * @param mixed?
   * @param array
   *
   * @return bool TRUE if it went ok, FALSE will prevent further processing of the queue.
   */
  public static function processQueueItem($queueTaskContext, $data) {

    // Get instance ID.
    $id = (int) $data['inlayID'];

    // Get the Inlay Object from database if we don't have it cached.
    if (($id > 0) && !isset(static::$instanceCache[$id])) {
      $inlayData = \Civi\Api4\Inlay::get(FALSE)
        ->setCheckPermissions(FALSE)
        ->addWhere('id', '=', (int) $data['inlayID'])
        ->execute()->first();
      $inlay = new static();
      $inlay->loadFromArray($inlayData);
      // Store on cache.
      static::$instanceCache[$id] = $inlay;
    }

    // Error if we couldn't find it.
    if (empty(static::$instanceCache[$id])) {
      throw new \RuntimeException("Invalid Inlay/Petition queue item, failed to load instance.");
    }

    // Finally, use it to process the data.
    $error = $inlay->processDeferredSubmission($data);
    if ($error) {
      // ?? How to handle errors.
      // @todo
    }

    // Move on to next item in queue.
    return TRUE;
  }
  /**
   * Process a queued submission.
   *
   * This is where the bulk of the work is done.
   *
   * @var array $data
   */
  public function processDeferredSubmission($data) {

    // Find Contact with XCM.
    $params = $data + ['contact_type' => 'Individual'];
    $contactID = civicrm_api3('Contact', 'getorcreate', $params)['id'] ?? NULL;
    if (!$contactID) {
      Civi::log()->error('Failed to getorcreate contact with params: ' . json_encode($params));
      throw new \Civi\Inlay\ApiException(500, ['error' => 'Server error: XCM1']);
    }

    // Write an activity
    if (!$this->contactAlreadySigned($contactID)) {
      // Not signed yet.
      $this->addSignedPetitionActivity($contactID, $data);
    }

    // No error
    return '';
  }

  /**
   * Has the given contact signed this petition already?
   *
   * @var int $contactID
   *
   * @return bool
   */
  public function contactAlreadySigned($contactID) {

    $subject = $this->getName();
    $activityTypeID = $this->getActivityTypeID();

    $found = (bool) \CRM_Core_DAO::singleValueQuery("
        SELECT a.id
        FROM civicrm_activity a
        INNER JOIN civicrm_activity_contact ac
        ON a.id = ac.activity_id
            AND ac.record_type_id = 3 /*target*/
            AND ac.contact_id = %1
        WHERE a.activity_type_id = %2 AND a.subject = %3
        LIMIT 1
      ", [
        1 => [$contactID, 'Integer'],
        2 => [$activityTypeID, 'Integer'],
        3 => [$subject, 'String'],
      ]);

    return $found;
  }
  /**
   * @var int $contactID
   * @var array $data
   */
  public function addSignedPetitionActivity($contactID, $data) {
    $activityCreateParams = [
      'activity_type_id'     => $this->getActivityTypeID(),
      'target_id'            => $contactID,
      'subject'              => $this->getName(),
      'status_id'            => 'Completed',
      'source_contact_id'    => $contactID,
      // 'source_contact_id' => \CRM_Core_BAO_Domain::getDomain()->contact_id,
      // 'details'           => $details,
    ];
    $result = civicrm_api3('Activity', 'create', $activityCreateParams);

    return $result;
  }
  /**
   * Validate and clean up input data.
   *
   * Possible outputs:
   * - first_name
   * - last_name
   * - email
   * - token TRUE|unset
   *
   * @param array $data
   *
   * @return array
   */
  public function cleanupInput($data) {
    /** @var Array errors in this array, it will later be converted to a string. */
    $errors = [];
    /** @var Array Collect validated data in this array */
    $valid = [];

    // Check we have what we need.
    foreach (['first_name', 'last_name', 'email'] as $field) {
      $val = trim($data[$field] ?? '');
      if (empty($val)) {
        $errors[] = str_replace('_', ' ', $field) . " required.";
      }
      else {
        if ($field === 'email' && !filter_var($val, FILTER_VALIDATE_EMAIL)) {
          $errors[] = "invalid email address";
        }
        else {
          $valid[$field] = $val;
        }
      }
    }
    if ($errors) {
      throw new \Civi\Inlay\ApiException(400, ['error' => implode(', ', $errors)]);
    }

    // Data is valid.
    if (!empty($data['token'])) {
      // There is a token, check that now.
      try {
        $this->checkCSRFToken($data['token'], $valid);
        $valid['token'] = TRUE;
      }
      catch (\InvalidArgumentException $e) {
        // Token failed. Issue a public friendly message, though this should
        // never be seen by anyone legit.
        Civi::log()->notice("Token error: " . $e->getMessage . "\n" . $e->getTraceAsString());
        throw new \Civi\Inlay\ApiException(400,
          ['error' => "Mysterious problem, sorry! Code " . substr($e->getMessage(), 0, 3)]);
      }

      // Validation that is more expensive, and for fields where invalid data
      // would likely represent misuse of the form is done now - after the
      // token check, to avoid wasting server resources on spammers trying to
      // randomly post to the endpoint.

      /*
      if ($this->config['phoneAsk'] && !empty($data['phone'])) {
        // Check the phone.
        $valid['phone'] = preg_replace('/[^0-9+]/', '', $data['phone']);
      }
       */
    }


    return $valid;
  }

  /**
   * Returns a URL to a page that lets an admin user configure this Inlay.
   *
   * @return string URL
   */
  public function getAdminURL() {

  }

  /**
   * Get the Javascript app script.
   *
   * This will be bundled with getInitData() and some other helpers into a file
   * that will be sourced by the client website.
   *
   * @return string Content of a Javascript file.
   */
  public function getExternalScript() {
    return file_get_contents(E::path('dist/inlay-petition.js'));
  }


  /**
   * Custom function to get unis.
   *
   * @return array contact ID => university name
   */
  public function getUnis($checkID=NULL) {
    $unis = [];

    $api = \Civi\Api4\Contact::get(FALSE)
      ->addSelect('legal_name', 'id')
      ->addWhere('contact_type', '=', 'Organization')
      ->addWhere('contact_sub_type', 'IN', ['ACIHECollege', 'ACIUniversity'])
      ->addWhere('is_deleted', '=', FALSE)
    ;
    if ($checkID) {
      $api->addWhere('id', '=', $checkID);
    }
    $result = $api
      ->addOrderBy('legal_name', 'ASC')
      ->execute();

    foreach ($result as $uni) {
      $unis[] = ['id' => $uni['id'], 'name' => $uni['legal_name']];
    }

    return $unis;
  }
}

