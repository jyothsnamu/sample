<?php

namespace Drupal\webform_submissions_download\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\custom_webform\Webform;
use Drupal\user\Entity\User;
use Drupal\webform\Entity\Webform;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a OGPVP Webform Submissions form.
 */
class WebformSubmissionDownload extends FormBase {

  private AccountInterface $account;
  private ImpWebform $customWebform;

  /**
   * @param AccountInterface $account
   */
  public function __construct(AccountInterface $account, ImpWebform $custom_webform) {
    $this->account = $account;
    $this->customWebform = $custom_webform;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    // Instantiates this form class.
    return new static(
    // Load the service required to construct this class.
      $container->get('current_user'),
      $container->get('custom_webform.webform')
    );
  }
  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'webform_submission_download';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $agencies = [];
    $current_user = User::load(\Drupal::currentUser()
      ->id());
    if ($current_user->hasRole('user_omb') || $current_user->hasRole('administrator')) {
      $tree = \Drupal::entityTypeManager()
        ->getStorage('taxonomy_term')
        ->loadTree('type', 0, 1, TRUE);

      foreach ($tree as $term) {
        $agencies[$term->id()] = $term->label();
      }
    }
    elseif (!empty($current_user->field_code->getValue())) {
      $current_user_agency_code = $current_user->field_code->getString();
      $term_obj = $this->customWebform->getTermObjectFromCode($current_user_code);
      $agencies[$term_obj->id()] = $term_obj->label();
    }
    $form['type'] = [
      '#type' => 'select',
      '#title' => $this->t('Select type'),
      '#options' => $agencies,
      '#required' => TRUE,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {

  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $agency_tid = $form_state->getValue('type');

    $this->_download_agency_survey_results($agency_tid);
  }

  public function _download_agency_survey_results($agency_tid) {
    $messenger = \Drupal::service('messenger');
    $custom_webform_service = \Drupal::service('imppay_custom_webform.imp_webform');
    global $base_url;
    $selected_sids = $this->customWebform->getSubmissionIds($agency_tid);

    if (!empty($selected_sids)) {
      
      //Set webform export options.
      $webform = Webform::load('webform_id');
      $webform_exporter = \Drupal::service('webform_submission.exporter');
      $export_options['file_name'] = 'Webform survey';

      $export_options['exporter'] = 'delimited';
      $export_options['excluded_columns'] = [
        'serial' => 'serial',
        'sid' => 'sid',
        'uuid' => 'uuid',
        'token' => 'token',
        'uri' => 'uri',
        'current_page' => 'current_page',
        'remote_addr' => 'remote_addr',
        'langcode' => 'langcode',
        'webform_id' => 'webform_id',
        'entity_type' => 'entity_type',
        'entity_id' => 'entity_id',
        'locked' => 'locked',
        'sticky' => 'sticky',
        'notes' => 'notes',
        'current_card' => 'current_card',
      ];


      $export_options['range_type'] = 'selected';
      $export_options['selected_array'] = $selected_sids;

      $export_options['base_file_name'] = 'Survey-results-' . date('m-d-Hi');
      $webform_exporter->setWebform($webform);
      $webform_exporter->setExporter($export_options);
      
      //Generate webform export.
      $webform_exporter->generate();
      
      //Get the location of the generated csv file to process.
      $file_path = $webform_exporter->getExportFilePath();

      $file_path = str_replace('.txt', '.zip', $file_path);
      $exported_complete_file_path = $base_url . '/' . $file_path;

      $messenger->deleteAll();

      $file_base_name = 'Survey-' . date('m-d-Hi') . '.csv';
      $complete_file_path = 'sites/default/files/submissions/csv/' . $file_base_name;
      $file_data = fopen($file_path, 'r+');

      $column = fgetcsv($file_data);

      $final_result_array = [];
      $final_result_array[] = $column;
      while ($row = fgetcsv($file_data)) {
        $agency_code = $row[8];

        $agency_term_obj = $custom_webform_service->getTermObjectFromCode($agency_code);
        if ($agency_term_obj) {
          $row[8] = $agency_term_obj->label();
        }
        $final_result_array[] = $row;
      }
      $f = fopen($complete_file_path, 'w');

      foreach ($final_result_array as $row) {
        fputcsv($f, $row);
      }
      fclose($f);

      $messenger->addStatus(['#markup' => '<p>Successfully exported submissions. <a href="/' . $complete_file_path . '">Click here</a> to download.</p>']);
    }
    else {
      $messenger->addError(['#markup' => '<p>No submissions available to download.</p>']);
    }
  }

}
