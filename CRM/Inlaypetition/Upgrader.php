<?php
use CRM_Inlaypetition_ExtensionUtil as E;

/**
 * Collection of upgrade steps.
 */
class CRM_Inlaypetition_Upgrader extends CRM_Inlaypetition_Upgrader_Base {

  // By convention, functions that look like "function upgrade_NNNN()" are
  // upgrade tasks. They are executed in order (like Drupal's hook_update_N).

  /**
   * Ensure we have our activity type ready.
   *
   */
  public function install() {

    $petitionActivityType = $this->getSignedActivityTypeValue();

    if (!$petitionActivityType) {
      // Petition activity type did not exist, create it now.
      \Civi\Api4\OptionValue::create(FALSE)
       ->addValue('option_group_id:name', 'activity_type')
       ->addValue('name', 'inlay_petition')
       ->addValue('label', 'Signed Petiton (Inlay)')
       ->addValue('is_active', TRUE)
       ->addValue('description', 'Signed a petition provided using the Inlay framework. The subject will be the name of the petition signed.')
       ->addValue('icon', 'fa-hand-rock-o')
       ->execute();
    }

  }

  protected function getSignedActivityTypeValue() :int {
    $petitionActivityType = \Civi\Api4\OptionValue::get(FALSE)
      ->addSelect('value')
      ->addWhere('option_group_id:name', '=', 'activity_type')
      ->addWhere('name', '=', 'inlay_petition')
      ->execute()->first()['value'] ?? 0;

    return (int) $petitionActivityType;
  }


  /**
   * Example: Work with entities usually not available during the install step.
   *
   * This method can be used for any post-install tasks. For example, if a step
   * of your installation depends on accessing an entity that is itself
   * created during the installation (e.g., a setting or a managed entity), do
   * so here to avoid order of operation problems.
   */
  public function postInstall() {
    $this->ensureCustomGroupForActivities();
  }

  public function ensureCustomGroupForActivities() {

    $petitionActivityType = $this->getSignedActivityTypeValue();

    $customGroup = \Civi\Api4\CustomGroup::get(FALSE)
      ->addWhere('name', '=', 'inlaypetition_signer')
      ->execute()->first();

    if (!$customGroup) {
      // Need to create this now
      $customGroup = \Civi\Api4\CustomGroup::create(FALSE)
        ->addValue('name', 'inlaypetition_signer')
        ->addValue("title", E::ts("Petition signature details"))
        ->addValue("extends", "Activity")
        ->addValue("extends_entity_column_value", [$petitionActivityType])
        ->addValue("style", "Inline")
        ->addValue("collapse_display", false)
        ->addValue("is_active", true)
        ->addValue("table_name", "civicrm_inlaypetition_signer_details")
        ->addValue("is_multiple", false)
        ->addValue("collapse_adv_display", true)
        ->addValue("is_reserved", false)
        ->addValue("is_public", false)
        ->execute()->first()['id'];
    }

    // We need an option group.
    $optionGroup = \Civi\Api4\OptionGroup::get(FALSE)
      ->addWhere('name', '=', 'inlaypetition_optin')
      ->execute()->first()['id'] ?? NULL;
    if (!$optionGroup) {
      // Create option group.
      $optionGroup = \Civi\Api4\OptionGroup::create(FALSE)
        ->addValue('name', 'inlaypetition_optin')
        ->addValue('title', 'Inlay Petition Signer opt-in')
        ->addValue('is_active', TRUE)
        ->addValue("is_reserved", false)
        ->execute()->first()['id'] ?? NULL;
    }
    $optionValues = \Civi\Api4\OptionValue::get(FALSE)
      ->addWhere('option_group_id:name', '=', 'inlaypetition_optin')
      ->execute()->indexBy('value');

    // Create options.
    foreach ([
      ['value' => 'yes_added', 'label' => 'Yes, added to group'],
      ['value' => 'yes_in_already', 'label' => 'Yes, but already in group'],
      ['value' => 'no_in_already', 'label' => 'No change, but already in group'],
      ['value' => 'no_not_in', 'label' => 'No change, not in group'],
    ] as $opt) {

    if (empty($optionValues[$opt['value']])) {
      // option not found, create now.
      $optionValues = \Civi\Api4\OptionValue::create(FALSE)
        ->addValue('option_group_id', $optionGroup)
        ->addValue('value', $opt['value'])
        ->addValue('name', $opt['value'])
        ->addValue('label', $opt['label'])
        ->addValue('is_active', TRUE)
        ->execute();
      }
    }

    // Create the Opt in field.
    $optinField = \Civi\Api4\CustomField::get(FALSE)
      ->addWhere('custom_group_id', '=', $customGroup)
      ->addWhere('name', '=', 'inlaypetition_signer_optin')
      ->execute()->first();

    if (!$optinField) {
      // Create the field now.
      $optinField = \Civi\Api4\CustomField::create(FALSE)
        ->addValue('custom_group_id', $customGroup)
        ->addValue('name', 'inlaypetition_signer_optin')
        ->addValue('label', 'Did they opt in for updates?')
        ->addValue('data_type', 'String')
        ->addValue('html_type', 'Select')
        ->addValue('is_searchable', TRUE)
        ->addValue('is_active', TRUE)
        ->addValue('text_length', 24)
        ->addValue('column_name', 'signer_optin')
        ->addValue('option_group_id', $optionGroup)
        ->execute();
    }
  }
  /**
   * Example: Run an external SQL script when the module is uninstalled.
   */
  // public function uninstall() {
  //  $this->executeSqlFile('sql/myuninstall.sql');
  // }

  /**
   * Example: Run a simple query when a module is enabled.
   */
  // public function enable() {
  //  CRM_Core_DAO::executeQuery('UPDATE foo SET is_active = 1 WHERE bar = "whiz"');
  // }

  /**
   * Example: Run a simple query when a module is disabled.
   */
  // public function disable() {
  //   CRM_Core_DAO::executeQuery('UPDATE foo SET is_active = 0 WHERE bar = "whiz"');
  // }

  /**
   * Example: Run a couple simple queries.
   *
   * @return TRUE on success
   * @throws Exception
   */
  public function upgrade_0001() {
    $this->ctx->log->info('Applying update 1');
    $this->ensureCustomGroupForActivities();
    return TRUE;
  }

  /**
   * Example: Run an external SQL script.
   *
   * @return TRUE on success
   * @throws Exception
   */
  // public function upgrade_4201() {
  //   $this->ctx->log->info('Applying update 4201');
  //   // this path is relative to the extension base dir
  //   $this->executeSqlFile('sql/upgrade_4201.sql');
  //   return TRUE;
  // }


  /**
   * Example: Run a slow upgrade process by breaking it up into smaller chunk.
   *
   * @return TRUE on success
   * @throws Exception
   */
  // public function upgrade_4202() {
  //   $this->ctx->log->info('Planning update 4202'); // PEAR Log interface

  //   $this->addTask(E::ts('Process first step'), 'processPart1', $arg1, $arg2);
  //   $this->addTask(E::ts('Process second step'), 'processPart2', $arg3, $arg4);
  //   $this->addTask(E::ts('Process second step'), 'processPart3', $arg5);
  //   return TRUE;
  // }
  // public function processPart1($arg1, $arg2) { sleep(10); return TRUE; }
  // public function processPart2($arg3, $arg4) { sleep(10); return TRUE; }
  // public function processPart3($arg5) { sleep(10); return TRUE; }

  /**
   * Example: Run an upgrade with a query that touches many (potentially
   * millions) of records by breaking it up into smaller chunks.
   *
   * @return TRUE on success
   * @throws Exception
   */
  // public function upgrade_4203() {
  //   $this->ctx->log->info('Planning update 4203'); // PEAR Log interface

  //   $minId = CRM_Core_DAO::singleValueQuery('SELECT coalesce(min(id),0) FROM civicrm_contribution');
  //   $maxId = CRM_Core_DAO::singleValueQuery('SELECT coalesce(max(id),0) FROM civicrm_contribution');
  //   for ($startId = $minId; $startId <= $maxId; $startId += self::BATCH_SIZE) {
  //     $endId = $startId + self::BATCH_SIZE - 1;
  //     $title = E::ts('Upgrade Batch (%1 => %2)', array(
  //       1 => $startId,
  //       2 => $endId,
  //     ));
  //     $sql = '
  //       UPDATE civicrm_contribution SET foobar = whiz(wonky()+wanker)
  //       WHERE id BETWEEN %1 and %2
  //     ';
  //     $params = array(
  //       1 => array($startId, 'Integer'),
  //       2 => array($endId, 'Integer'),
  //     );
  //     $this->addTask($title, 'executeSql', $sql, $params);
  //   }
  //   return TRUE;
  // }

}
