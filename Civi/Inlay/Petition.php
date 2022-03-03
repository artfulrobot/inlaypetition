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

  /**
   * Access this via getActivityTypeID()
   */
  public static $petitionActivityType = NULL;

  /**
   * Access this via getSignerOptInFieldID()
   */
  public static $signerOptInFieldID = NULL;

  public static $typeName = 'Petition/signup';

  /**
   * Cache so that when processing a set of queued signups we don't have to
   * load the Inlay instance for each time.
   *
   * Keyed by instance ID.
   */
  public static $instanceCache = [];

  public static $defaultConfig = [
    // Nb. the inlay's name is used for the petition activity subject.

    // Form
    'publicTitle'      => '',
    'target'           => NULL,
    'uxMode'           => 'petition',
    // Page 1
    'introHTML'        => '',
    'askPostcode'      => 'no', // no|optional|required
    'askPhone'         => 'no', // no|optional|required
    'preOptinHTML'     => '',
    'optinMode'        => 'radios', // radios|none|checkbox
    'optinYesText'     => 'I would like to receive further information from Keep Our NHS Public about its campaigns and activities, in the future',
    'optinNoText'      => 'I’m happy as I am, thanks',
    'smallprintHTML'   => NULL,
    'submitButtonText' => 'Sign',
    // Page 2 (Social Media ask)
    'shareAskHTML'     => '<h2>Thanks!</h2><p>Can you share this?</p>',
    'socials'          => ['twitter', 'facebook', 'email', 'whatsapp'],
    'tweet'            => '',
    'whatsappText'     => '',
    // Page 3
    'finalHTML'        => '<h2>Thank you</h2><p>Are you able to make a donation to our work?</p>',
    'thanksMsgTplID'   => NULL,
    // Internals
    'mailingGroup'     => NULL,
    'useQueue'         => TRUE,
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

    $data = [];
    foreach ([
      // UX
      'uxMode',
      // Top bit
      'publicTitle', 'target',
      // First view
      'introHTML', 'askPostcode', 'askPhone', 'preOptinHTML', 'optinMode', 'optinYesText', 'optinNoText', 'smallprintHTML', 'submitButtonText',
      // Social share ask
      'shareAskHTML', 'socials', 'tweet', 'whatsappText',
      // Final thanks.
      'finalHTML',
    ] as $_) {
      $data[$_] = $this->config[$_] ?? '';
    }

    // Social share data.
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

    // Custom output per uxMode
    switch ($this->config['uxMode']) {
    case 'petition':
      $data['init'] = 'inlayPetitionInit';
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
      break;

    case 'signup':
      $data['init'] = 'inlayPetitionInit';
      break;

    default:
      // Should never happen.
      throw new \InvalidArgumentException("Bad configuration on Petition/signup Inlay " . $this->getID());
    }

    return $data;
  }

  /**
   * Find our activity type
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
   * Find our activity type
   */
  public static function getSignerOptInFieldID() {
    if (!static::$signerOptInFieldID) {
      // Look it up.
      static::$signerOptInFieldID = \Civi\Api4\CustomField::get(FALSE)
        ->addWhere('custom_group_id:name', '=', 'inlaypetition_signer')
        ->addWhere('name', '=', 'inlaypetition_signer_optin')
        ->execute()->first()['id'];
    }
    return static::$signerOptInFieldID;
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

    Civi::log()->debug('Petition inlay request: ' . json_encode($request->getBody()));
    $data = $this->cleanupInput($request->getBody());

    if (empty($data['token'])) {
      // Unsigned request. Issue a token that will be valid in 5s time and lasts 2mins max.
      return ['token' => $this->getCSRFToken(['data' => $data, 'validFrom' => 5, 'validTo' => 120])];
    }

    if ($this->config['useQueue']) {
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
    }
    else {
      // Immediate processing.
      $this->processDeferredSubmission($data);
    }

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
    $error = static::$instanceCache[$id]->processDeferredSubmission($data);
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

    Civi::log()->debug('processDeferredSubmission: ' . json_encode($data));

    // Find Contact with XCM.
    $params = [
      'contact_type' => 'Individual',
      'email'        => $data['email'],
      'first_name'   => $data['first_name'],
      'last_name'    => $data['last_name'],
    ];
    $contactID = civicrm_api3('Contact', 'getorcreate', $params)['id'] ?? NULL;
    if (!$contactID) {
      Civi::log()->error('Failed to getorcreate contact with params: ' . json_encode($params));
      throw new \Civi\Inlay\ApiException(500, ['error' => 'Server error: XCM1']);
    }

    // Handle optin.
    if (!empty($this->config['mailingGroup'])) {
      $optinMode = $this->config['optinMode'];
      $optinRecord = '';

      $isInGroup = \Civi\Api4\GroupContact::get(FALSE)
        ->selectRowCount()
        ->addWhere('group_id', '=', $this->config['mailingGroup'])
        ->addWhere('contact_id', '=', $contactID)
        ->addWhere('status:name', '=', 'Added')
        ->execute()->count() > 0;

      // If there was no optin (e.g. signup form)
      // or if the user actively checked/selected yes, then sign up.
      if ($optinMode === 'none'
        || ($data['optin'] ?? 'no') === 'yes') {

        // Ensure contact is in the group.
        if ($isInGroup) {
          // Already in group.
          $optinRecord = 'yes_in_already';
        }
        else {
          $optinRecord = 'yes_added';
          $this->addContactToGroup($contactID);
        }
      }
      else{
        // Contact not to be added (or removed)
        $optinRecord = $isInGroup ? 'no_in_already' : 'no_not_in';
      }
    }
    else{
      Civi::log()->debug("processDeferredSubmission: NOT adding $contactID to group: no group configured");
    }

    // Add optin record to data
    $data['inlaypetition_signer_optin'] = $optinRecord;

    // Write an activity
    if ($this->config['uxMode'] === 'signup') {
      // For signup, always.
      $this->addSignedPetitionActivity($contactID, $data);
    }
    elseif ($this->config['uxMode'] === 'petition') {
      if (!$this->contactAlreadySigned($contactID)) {
        // For petition, only record new activity if not done before.
        $this->addSignedPetitionActivity($contactID, $data);
      }
      // ? update the activity here?
    }

    // Thank you.
    if (!empty($this->config['thanksMsgTplID'])) {
      $this->sendThankYouEmail($contactID, $data);
    }

    // No error
    return '';
  }

  /**
   * Has the given contact signed this petition already?
   *
   * @var int $contactID
   *
   * @return int Activity ID or 0
   */
  public function contactAlreadySigned($contactID) :int {

    $subject = $this->getName();
    $activityTypeID = $this->getActivityTypeID();

    $found = (int) \CRM_Core_DAO::singleValueQuery("
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
   * Might be a petition or a signup.
   *
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
      'location'             => $data['location'] ?? '',
      // 'details'           => $details,
    ];
    if ($data['inlaypetition_signer_optin']) {
      $activityCreateParams['custom_' . static::getSignerOptInFieldID()] = $data['inlaypetition_signer_optin'];
    }

    $result = civicrm_api3('Activity', 'create', $activityCreateParams);
    return $result;
  }
  /**
   * Add the contact to the mailing group.
   *
   * @param int $contactID
   */
  public function addContactToGroup($contactID) {
    $contacts = [$contactID];
    $groupID = $this->config['mailingGroup'];
    \CRM_Contact_BAO_GroupContact::addContactsToGroup($contacts, $groupID, 'Petition', $status='Added');
  }
  /**
   * Send the thank you email to the person who signed up.
   *
   * @param int $contactID
   * @param array $data
   *    The validated input data.
   */
  public function sendThankYouEmail($contactID, $data) {

    $from = civicrm_api3('OptionValue', 'getvalue', [ 'return' => "label", 'option_group_id' => "from_email_address", 'is_default' => 1]);

    try {
      // This was the original, using MessageTemplate.send API
      //
      // // We use the email send in the data, as that's what they'd expect.
      // $params = [
      //   'id'             => $this->config['thanksMsgTplID'],
      //   'from'           => $from,
      //   'to_email'       => $data['email'],
      //   // 'bcc'            => "forums@artfulrobot.uk",
      //   'contact_id'     => $contactID,
      //   'disable_smarty' => 1,
      // /*
      // 'template_params' =>
      // [ 'foo' => 'hello',
      // // {$foo} in templates 'bar' => '123',
      // // {$bar} in templates ],
      // ];
      // civicrm_api3('MessageTemplate', 'send', $params);
      //  */

      // This is the new one using Email.send from the EmailAPI extension, which also saves it as an activity.
      $params = [
        'template_id'                  => $this->config['thanksMsgTplID'],
        'alternative_receiver_address' => $data['email'],
        // 'bcc'                       => "forums@artfulrobot.uk",
        'contact_id'                   => $contactID,
        'disable_smarty'               => 1,
        'activity_details'             => 'tplName',
      ];
      civicrm_api3('Email', 'send', $params);
    }
    catch (\Exception $e) {
      // Log silently.
      Civi::log()->error("Failed to send MessageTemplate with params: " . json_encode($params, JSON_PRETTY_PRINT) . " Caught " . get_class($e) . ": " . $e->getMessage());
    }
  }
  /**
   * Validate and clean up input data.
   *
   * Possible outputs:
   * - first_name
   * - last_name
   * - email
   * - location (URL)
   * - optin
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
        $errors[] = str_replace('_', ' ', $field) . " required";
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

    // Clean up location
    $location = trim($data['location'] ?? '');
    if ($location) {
      $containsEmoji = (preg_match("/[\u{1f300}-\u{1f5ff}\u{e000}-\u{f8ff}]/u", $location));
      $looksFairEnough = preg_match('@^https?://[^/]+/[^<>]+$@', $location);
      if (!$containsEmoji && $looksFairEnough && strlen($location) < 255) {
        // Looks fine.
        $valid['location'] = $location;
      }
      else {
        // Hmmm looks dodgy.
        Civi::log()->notice("Dodgy location received with petition submission with email: '$valid[email]': " . $location);
        $valid['location'] = mb_substr(preg_replace('@[<>\u{1f300}-\u{1f5ff}\u{e000}-\u{f8ff}]+@u', '_', $location), 0, 200) . ' (cleaned)';
      }
    }

    // Optin.
    switch ($this->config['optinMode']) {
    case 'radios':
      // We require the 'optin' data.
      if (!in_array($data['optin'] ?? '', ['yes', 'no'])) {
        // Error.
        throw new \Civi\Inlay\ApiException(400, ['error' => 'Please choose to opt-in to updates or not']);
      }
      $valid['optin'] = $data['optin'];
      break;

    case 'checkbox':
      if (($data['optin'] ?? '') === 'yes') {
        $valid['optin'] = 'yes';
      }
      else {
        // Checkboxes don't submit 'no' for off.
        $valid['optin'] = 'no';
      }
      break;

    default:
      $valid['optin'] = 'yes';
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
        Civi::log()->notice("Token error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
        throw new \Civi\Inlay\ApiException(400,
          ['error' => "Mysterious problem, sorry! Code " . substr($e->getMessage(), 0, 3)],
          $e->getMessage() /* provide full message as internalError */
        );
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

}

