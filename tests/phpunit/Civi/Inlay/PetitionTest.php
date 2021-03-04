<?php
namespace Civi\Inlay;

use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use Civi\Inlay\Type as InlayType;
use Civi\Inlay\ApiRequest;

/**
 * Job.Processpetitioninlays API Test Case
 * This is a generic test class implemented with PHPUnit.
 * @group headless
 */
class PetitionTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {
  use \Civi\Test\Api3TestTrait;

  /** push to this stack what you want to come back from a Contact.getorcreate call.
   * This means we don't have to create and configure xcm in our tests.
   * @var array
   */
  public static $getorcreateResponses = [];

  /** @var int */
  protected $groupID;
  /** @var ?array */
  protected $inlayData;
  /** @var int */
  protected $contactID;

  /** @var int custom field id */
  protected $customOptInFieldID;
  /**
   * Set up for headless tests.
   *
   * Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
   *
   * See: https://docs.civicrm.org/dev/en/latest/testing/phpunit/#civitest
   */
  public function setUpHeadless() {
    return \Civi\Test::headless()
      ->install('inlay')
      ->installMe(__DIR__)
      ->apply();
  }

  /**
   * The setup() method is executed before the test is executed (optional).
   */
  public function setUp() {
    parent::setUp();
    // Create a newsletter group.
    $this->groupID = (int) (\Civi\Api4\Group::create(FALSE)
      ->addValue('name', 'newsletter_group')
      ->addValue('title', 'newsletter_group')
      ->addValue('group_type', [ 2 ])
      ->execute()->first()['id'] ?? NULL);
    $this->assertGreaterThan(0, $this->groupID);

    // Create our contact.
    $this->contactID = \Civi\Api4\Contact::create(FALSE)
         ->addValue('contact_type:name', 'Individual')
         ->addValue('display_name', 'Wilma Test')
         ->addChain('name_me_0',
           \Civi\Api4\Email::create()
           ->addValue('contact_id', '$id')
           ->addValue('email', "wilma@example.org")
         )
      ->execute()->first()['id'];
    $this->assertGreaterThan(0, $this->contactID);

    $this->customOptInFieldID = Petition::getSignerOptInFieldID();
  }

  /**
   * The tearDown() method is executed after the test was executed (optional)
   * This can be used for cleanup.
   */
  public function tearDown() {
    parent::tearDown();
  }

  /**
   * This runs through a valid submission, including tokens and optin.
   */
  public function testPrimaryUseCase() {

    // Create a petition inlay.
    $this->createPetitionInlay();

    // Test initial request, without token, should return a token.
    $request = $this->getRequestObject(FALSE);
    $result = $this->inlayPetition->processRequest($request);
    $this->assertInternalType('array', $result);
    $this->assertArrayHasKey('token', $result);

    // Now test valid request with token.
    $request = $this->getRequestObject();
    // This response should call Contact.getorcreate, so we mock that now.
    static::$getorcreateResponses[] = ['id' => $this->contactID];
    try {
      $result = $this->inlayPetition->processRequest($request);
    }
    catch (\ApiException $e) {
      $this->fail("Exception: " . $e->getMessage() . "\nInternal: " . $e->getInternalError() . "\n");
    }

    $this->assertSingleSignedPetitionActivity();
    $this->assertContactInNewsletterGroup();
  }

  /**
   */
  public function testSigningTwiceDoesNotCreateMultipleActivities() {

    // Create a petition inlay.
    $this->createPetitionInlay();

    foreach ([0, 1] as $_) {
      $request = $this->getRequestObject();
      static::$getorcreateResponses[] = ['id' => $this->contactID];
      $this->inlayPetition->processRequest($request);
      $this->assertSingleSignedPetitionActivity();
    }
  }

  /**
   * Test optin recording in the custom field.
   *
   * i.e. it records in a custom field on the signed activity whether you opted
   * in or not and whether you were already in the group at the time.
   */
  public function testOpninRecording() {

    // Create a petition inlay.
    $this->createPetitionInlay();

    // Contact not in group, does not opt in.
    $request = $this->getRequestObject(TRUE, ['optin' => 'no']);
    static::$getorcreateResponses[] = ['id' => $this->contactID];
    $this->inlayPetition->processRequest($request);
    // Should not be in group; and no_not_in
    $this->assertContactNotInNewsletterGroup();
    $activityID = $this->assertSingleSignedPetitionActivity('no_not_in');
    // delete activity.
    \Civi\Api4\Activity::delete(FALSE)
      ->addWhere('id', '=', $activityID)
      ->execute();

    // Contact not in group, does opt in.
    $request = $this->getRequestObject(TRUE, ['optin' => 'yes']);
    static::$getorcreateResponses[] = ['id' => $this->contactID];
    $this->inlayPetition->processRequest($request);
    // Should be in group now; and yes_added
    $this->assertContactInNewsletterGroup();
    $activityID = $this->assertSingleSignedPetitionActivity('yes_added');
    // delete activity.
    \Civi\Api4\Activity::delete(FALSE)
      ->addWhere('id', '=', $activityID)
      ->execute();

    // Contact in group, does not opt in.
    $request = $this->getRequestObject(TRUE, ['optin' => 'no']);
    static::$getorcreateResponses[] = ['id' => $this->contactID];
    $this->inlayPetition->processRequest($request);
    // Should (still) be in group; and no_in_already
    $this->assertContactInNewsletterGroup();
    $activityID = $this->assertSingleSignedPetitionActivity('no_in_already');
    // delete activity.
    \Civi\Api4\Activity::delete(FALSE)
      ->addWhere('id', '=', $activityID)
      ->execute();

    // Contact in group, does opt in.
    $request = $this->getRequestObject(TRUE, ['optin' => 'yes']);
    static::$getorcreateResponses[] = ['id' => $this->contactID];
    $this->inlayPetition->processRequest($request);
    // Should (still) be in group; and no_in_already
    $this->assertContactInNewsletterGroup();
    $activityID = $this->assertSingleSignedPetitionActivity('yes_in_already');

  }

  /**
   * Check that the data received is correctly coerced into a yes/no for optin.
   *
   * This tests the treatment of incoming data cleaning, not how the clean data
   * is then processed. See next test for that.
   *
   * @dataProvider optinModeTestData
   */
  public function testOptinModes(string $description, string $mode, array $dataOverrides, string $expectedOptinValue) {

    $this->createPetitionInlay(['optinMode' => $mode]);
    try {
      $clean = $this->inlayPetition->cleanupInput($dataOverrides + [
        'first_name' => 'Wilma',
        'last_name'  => 'Test',
        'email'      => 'wilma@example.org',
        'publicID'   => 'aabbccddee',
      ]);
      $this->assertEquals($expectedOptinValue, $clean['optin'] ?? NULL, $description);
    }
    catch (ApiException $e) {
      $this->assertTrue($expectedOptinValue === 'Exception', "Got unexpected exception.");
    }
  }
  public function optinModeTestData() {
    return [
      [
        'None mode, optin should be yes when blank submitted.',
        'none',
        ['optin' => ''],
        'yes'
      ],
      [
        'None mode, optin should be yes when anything submitted.',
        'none',
        ['optin' => 'nonsense'],
        'yes'
      ],
      [
        'None mode, optin should be yes when nothing submitted.',
        'none',
        [],
        'yes'
      ],

      [
        'Checkbox mode, optin should be no when blank submitted.',
        'checkbox',
        ['optin' => ''],
        'no'
      ],
      [
        'Checkbox mode, optin should be yes when yes submitted.',
        'checkbox',
        ['optin' => 'yes'],
        'yes'
      ],
      [
        'Checkbox mode, should be no if anything other than yes submitted',
        'checkbox',
        ['optin' => 'nonsesnse'],
        'no'
      ],

      [
        'Radio mode, optin should be no when no submitted.',
        'radios',
        ['optin' => 'no'],
        'no'
      ],
      [
        'Radio mode, optin should be yes when yes submitted.',
        'radios',
        ['optin' => 'yes'],
        'yes'
      ],
      [
        'Radio mode, expect validation error if not yes/no',
        'radios',
        ['optin' => 'nonsesnse'],
        'Exception'
      ],
    ];
  }
  /**
   * Check that optin values yes|no add or don't add to group
   */
  public function testOptinsCorrectlyProcessed() {

    // Radios is default
    $this->createPetitionInlay();

    // Test no first.
    $request = $this->getRequestObject(TRUE, ['optin' => 'no']);
    static::$getorcreateResponses[] = ['id' => $this->contactID];
    $this->inlayPetition->processRequest($request);
    $this->assertSingleSignedPetitionActivity();
    $this->assertContactNotInNewsletterGroup();

    // Test when checkbox checked.
    $request = $this->getRequestObject(TRUE, ['optin' => 'yes']);
    static::$getorcreateResponses[] = ['id' => $this->contactID];
    $this->inlayPetition->processRequest($request);
    $this->assertSingleSignedPetitionActivity();
    $this->assertContactInNewsletterGroup();

  }
  /**
   * Test data validation
   *
   * This tests the treatment of incoming data cleaning, not how the clean data
   * is then processed.
   *
   * @dataProvider dataValidationTestData
   */
  public function testDataValidation(string $description, array $userData, array $expectedCleanData, $expectedExceptionResult = NULL) {

    $this->createPetitionInlay();
    try {
      // We're not testing token checking, so provide a valid token. This ensures we get the clean data at the end.
      $userData['token'] = $this->inlayPetition->getCSRFToken(['data' => $userData, 'validFrom' => 0]);
      $clean = $this->inlayPetition->cleanupInput($userData);
      $expectedCleanData['token'] = TRUE;
      $this->assertEquals($expectedCleanData, $clean, $description);
    }
    catch (ApiException $e) {
      $this->assertNotEmpty($expectedExceptionResult, "Unexpected exception: " . json_encode($e->responseObject));
      $this->assertEquals($expectedExceptionResult, $e->responseObject);
    }
  }
  public function dataValidationTestData() {
    return [
      [
        'All good',
        ['first_name' => 'Wilma', 'last_name' => 'Test', 'email' => 'wilma@example.org', 'optin' => 'no'],
        ['first_name' => 'Wilma', 'last_name' => 'Test', 'email' => 'wilma@example.org', 'optin' => 'no'],
      ],
      [
        'Invalid email',
        ['first_name' => 'Wilma', 'last_name' => 'Test', 'email' => 'wilmaexample.org', 'optin' => 'no'],
        [],
        ['error' => 'invalid email address']
      ],
      [
        'Missing names, emails (bypass HTML5 validation)',
        ['first_name' => '', 'last_name' => '', 'email' => 'wilma@example.org', 'optin' => 'no'],
        [],
        ['error' => 'first name required, last name required']
      ],
      [
        'Missing optin (bypass HTML5 validation)',
        ['first_name' => 'Wilma', 'last_name' => 'Test', 'email' => 'wilma@example.org', 'optin' => ''],
        [],
        ['error' => 'Please choose to opt-in to updates or not']
      ],
    ];
  }
  /**
   * Check that signup mode works.
   */
  public function testSignupUX() {

    $this->createPetitionInlay(['uxMode' => 'signup', 'optinMode' => 'none']);

    $request = $this->getRequestObject(TRUE, [
        'first_name' => 'Wilma',
        'last_name'  => 'Test',
        'email'      => 'wilma@example.org',
    ]);

    static::$getorcreateResponses[] = ['id' => $this->contactID];
    $this->inlayPetition->processRequest($request);
    $this->assertSingleSignedPetitionActivity('yes_added');
    $this->assertContactInNewsletterGroup();

    // Repeat
    static::$getorcreateResponses[] = ['id' => $this->contactID];
    $this->inlayPetition->processRequest($request);
    $this->assertLatestSignedPetitionActivity(2, 'yes_in_already');
    $this->assertContactInNewsletterGroup();
  }
  /**
   * Created a test petition inlay.
   *
   * @var $options array of overrides.
   */
  protected function createPetitionInlay($options = []) {

    // Create a petition inlay.
    $this->inlayData = \Civi\Api4\Inlay::create(FALSE)
      ->addValue('public_id', 'aabbccddee')
      ->addValue('name', 'Test Petition')
      ->addValue('class', 'Civi\\Inlay\\Petition')
      ->addValue('config', json_encode($options + [
            "publicTitle"  => "Test Petition Public Title",
            "target"       => 100,
            "uxMode"       => "petition",
            "optinMode"    => "radios",
            "optinYesText" => "Sign up",
            "optinNoText"  => "No change",
            "mailingGroup" => $this->groupID,
            "useQueue"     => FALSE
      ]))
      ->execute()->first();
    $this->assertGreaterThan(0, $this->inlayData['id']);
    $this->inlayPetition = InlayType::fromPublicID($this->inlayData['public_id']);
    $this->assertTrue($this->inlayPetition instanceof Petition);
  }

  /**
   * @var bool $withToken If true, add a valid token in. If not, add in the
   * publicID.
   * @var array $requestData request data
   * @var bool $applyDefaults if true add defaults, if not use requestData as is.
   */
  protected function getRequestObject($withToken = TRUE, $requestData = [], $applyDefaults = TRUE) :ApiRequest {

    // Common data
    if ($applyDefaults) {
      // No request data specified, use defaults.
      $requestData += [
        'first_name' => 'Wilma',
        'last_name'  => 'Test',
        'email'      => 'wilma@example.org',
        'optin'      => 'yes',
      ];
    }

    if (!$withToken) {
      // We want an initial request, without token.
      // This requests should will have a publicID.
      $requestData['publicID'] = $this->inlayData['public_id'];
    }
    else {
      // Add a token that's already valid so we're not waiting around.
      $requestData['token'] = $this->inlayPetition->getCSRFToken(['data' => $requestData, 'validFrom' => 0]);
    }

    $request = new ApiRequest();
    $request->setMethod('POST');
    $request->setInlay($this->inlayPetition);
    $request->setBody($requestData);

    return $request;
  }
  protected function assertContactInNewsletterGroup() {
    // Check that the person was added to the group.
    $groupContacts = \Civi\Api4\GroupContact::get(FALSE)
      ->addWhere('group_id', '=', $this->groupID)
      ->addWhere('contact_id', '=', $this->contactID)
      ->addWhere('status:name', '=', 'Added')
      ->execute();
    $this->assertEquals(1, $groupContacts->count());
  }
  protected function assertContactNotInNewsletterGroup() {
    // Check that the person was added to the group.
    $groupContacts = \Civi\Api4\GroupContact::get(FALSE)
      ->addWhere('group_id', '=', $this->groupID)
      ->addWhere('contact_id', '=', $this->contactID)
      ->addWhere('status:name', '=', 'Added')
      ->execute();
    $this->assertEquals(0, $groupContacts->count());
  }
  /**
   */
  protected function assertSingleSignedPetitionActivity($expectedOptin = NULL) :int {
    return $this->assertLatestSignedPetitionActivity(1, $expectedOptin);
  }
  /**
   */
  protected function assertLatestSignedPetitionActivity($expectedCount, $expectedOptin = NULL) :int {
    // Check that the correct activity was added.
    $activity = civicrm_api3('Activity', 'get', [
      'target_id'        => $this->contactID,
      'activity_type_id' => Petition::$petitionActivityType,
      'sequential'       => 1,
      'options'          => ['sort' => "id"],
      'return'           => ['id', 'subject', 'status_id', 'source_contact_id', 'custom_' . $this->customOptInFieldID ]
    ]);
    $this->assertEquals($expectedCount, $activity['count']);
    $activity = end($activity['values']);
    $this->assertEquals('Test Petition', $activity['subject']);
    $this->assertEquals(2, $activity['status_id']); /* Completed */
    $this->assertEquals($this->contactID, $activity['source_contact_id']);
    if ($expectedOptin !== NULL) {
      $this->assertEquals($expectedOptin, $activity['custom_' . $this->customOptInFieldID] ?? NULL);
    }
    return (int) $activity['id'];
  }
}

/**
 * This is a mock of civicrm_api3 in the Civi\Inlay namespace. It should therefore be called by
 * Civi\Inlay\Petition in place of the global one.
 */
function civicrm_api3($entity, $action, $params) {
  if ($entity === 'Contact' && $action === 'getorcreate') {
    // Special.
    if (empty(PetitionTest::$getorcreateResponses)) {
      throw new \RuntimeException("Contact.getorcreate called but no mock responses added.");
    }
    return array_pop(PetitionTest::$getorcreateResponses);
  }
  return \civicrm_api3($entity, $action, $params);
}

