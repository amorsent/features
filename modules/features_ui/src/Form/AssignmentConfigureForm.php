<?php

/**
 * @file
 * Contains \Drupal\features_ui\Form\AssignmentConfigureForm.
 */

namespace Drupal\features_ui\Form;

use Drupal\Component\Utility\SafeMarkup;
use Drupal\Component\Utility\Unicode;
use Drupal\Component\Utility\Xss;
use Drupal\features\FeaturesManagerInterface;
use Drupal\features\FeaturesAssignerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configures the configuration assignment methods for this site.
 */
class AssignmentConfigureForm extends FormBase {

  /**
   * The features manager.
   *
   * @var \Drupal\features\FeaturesManagerInterface
   */
  protected $featuresManager;

  /**
   * The package assigner.
   *
   * @var \Drupal\features\FeaturesAssignerInterface
   */
  protected $assigner;

  /**
   * Constructs a AssignmentConfigureForm object.
   *
   * @param \Drupal\features\FeaturesManagerInterface $features_manager
   *   The features manager.
   * @param \Drupal\features\FeaturesAssignerInterface $assigner
   *   The configuration assignment methods manager.
   */
  public function __construct(FeaturesManagerInterface $features_manager, FeaturesAssignerInterface $assigner) {
    $this->featuresManager = $features_manager;
    $this->assigner = $assigner;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('features.manager'),
      $container->get('features_assigner')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'features_assignment_configure_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $bundle_name = NULL) {
    $trigger = $form_state->getTriggeringElement();
    if ($trigger['#name'] == 'bundle[bundle_select]') {
      $bundle_name = $form_state->getValue(array('bundle', 'bundle_select'));
      $this->assigner->setCurrent($this->assigner->getBundle($bundle_name));
      // Setting the form values to the newly selected Bundle via Ajax is hard.
      // #default_values is ignored after form is submitted.
      // Can set #value in the Ajax callback, but doesn't work for checkboxes.
      // So let's just reload the page.
      return $this->redirect('features.assignment', array($bundle_name));
    }
    elseif ($trigger['#name'] == 'removebundle') {
      $current_bundle = $this->assigner->loadBundle($bundle_name);
      $bundle_name = $current_bundle->getMachineName();
      $this->assigner->removeBundle($bundle_name);
      return $this->redirect('features.assignment', array(''));
    }
    $current_bundle = $this->assigner->loadBundle($bundle_name);

    $settings = $current_bundle->getSettings();
    $enabled_methods = $current_bundle->getEnabledAssignments();
    $methods_weight = $current_bundle->getAssignmentWeights();

    // Add missing data to the methods lists.
    $assignment_info = $this->assigner->getAssignmentMethods();
    foreach ($assignment_info as $method_id => $method) {
      if (!isset($methods_weight[$method_id])) {
        $methods_weight[$method_id] = isset($method['weight']) ? $method['weight'] : 0;
      }
    }

    $form = array(
      '#attached' => array(
        'library' => array(
          'features_ui/drupal.features_ui.admin',
        ),
      ),
      // '#attributes' => array('class' => 'edit-bundles-wrapper'),
      '#tree' => TRUE,
      '#show_operations' => FALSE,
      'weight' => array('#tree' => TRUE),
      '#prefix' => '<div id="edit-bundles-wrapper">',
      '#suffix' => '</div>',
    );

    $form['bundle'] = array(
      '#type' => 'fieldset',
      '#title' => t('Bundle'),
      '#tree' => TRUE,
      '#weight' => -9,
    );

    $form['bundle']['bundle_select'] = array(
      '#title' => t('Bundle'),
      '#title_display' => 'invisible',
      '#type' => 'select',
      '#options' => $this->assigner->getBundleOptions(t('--New--')),
      '#default_value' => $current_bundle->getMachineName(),
      '#ajax' => array(
        'callback' => '::updateForm',
        'wrapper' => 'edit-bundles-wrapper',
      ),
    );

    if (!$current_bundle->isDefault()) {
      $form['bundle']['remove'] = array(
        '#type' => 'button',
        '#name' => 'removebundle',
        '#value' => t('Remove bundle'),
      );
    }

    $form['bundle']['name'] = array(
      '#title' => $this->t('Bundle name'),
      '#type' => 'textfield',
      '#description' => $this->t('A unique human-readable name of this bundle.'),
      '#default_value' => $current_bundle->isDefault() ? '' : $current_bundle->getName(),
    );

    $form['bundle']['machine_name'] = array(
      '#title' => $this->t('Machine name'),
      '#type' => 'machine_name',
      '#required' => FALSE,
      '#default_value' => $current_bundle->getMachineName(),
      '#description' => $this->t('A unique machine-readable name of this bundle.  Used to prefix exported packages. It must only contain lowercase letters, numbers, and underscores.'),
      '#machine_name' => array(
        'source' => array('bundle', 'name'),
      ),
    );

    $form['bundle']['description'] = array(
      '#title' => $this->t('Distribution description'),
      '#type' => 'textfield',
      '#default_value' => $current_bundle->isDefault() ? '' : $current_bundle->getDescription(),
      '#description' => $this->t('A description of the bundle.'),
      '#size' => 80,
    );

    $form['bundle']['is_profile'] = array(
      '#type' => 'checkbox',
      '#title' => t('Include install profile'),
      '#default_value' => $current_bundle->isProfile(),
      '#description' => $this->t('Select this option to have your configuration modules packaged into an install profile.'),
      '#attributes' => array(
        'data-add-profile' => 'status',
      ),
    );

    $show_if_profile_checked = array(
      'visible' => array(
        ':input[data-add-profile="status"]' => array('checked' => TRUE),
      ),
    );

    $form['bundle']['profile_name'] = array(
      '#title' => $this->t('Profile name'),
      '#type' => 'textfield',
      '#default_value' => $current_bundle->isProfile() ? $current_bundle->getProfileName() : '',
      '#description' => $this->t('The machine name (directory name) of your profile.'),
      '#size' => 30,
      // Show only if the profile.add option is selected.
      '#states' => $show_if_profile_checked,
    );

    // Order methods list by weight.
    asort($methods_weight);

    foreach ($methods_weight as $method_id => $weight) {

      // A packaging method might no longer be available if the defining module
      // has been disabled after the last configuration saving.
      if (!isset($assignment_info[$method_id])) {
        continue;
      }

      $enabled = isset($enabled_methods[$method_id]);
      $method = $assignment_info[$method_id];

      $method_name = SafeMarkup::checkPlain($method['name']);

      $form['weight'][$method_id] = array(
        '#type' => 'weight',
        '#title' => $this->t('Weight for !title package assignment method', array('!title' => Unicode::strtolower($method_name))),
        '#title_display' => 'invisible',
        '#default_value' => $weight,
        '#attributes' => array('class' => array('assignment-method-weight')),
        '#delta' => 20,
      );

      $form['title'][$method_id] = array('#markup' => $method_name);

      $form['enabled'][$method_id] = array(
        '#type' => 'checkbox',
        '#title' => $this->t('Enable !title package assignment method', array('!title' => Unicode::strtolower($method_name))),
        '#title_display' => 'invisible',
        '#default_value' => $enabled,
      );

      $form['description'][$method_id] = array('#markup' => Xss::filterAdmin($method['description']));

      $config_op = array();
      if (isset($method['config_route_name'])) {
        $config_op['configure'] = array(
          'title' => $this->t('Configure'),
          'url' => Url::fromRoute($method['config_route_name'], array('bundle_name' => $current_bundle->getMachineName())),
        );
        // If there is at least one operation enabled, show the operation
        // column.
        $form['#show_operations'] = TRUE;
      }
      $form['operation'][$method_id] = array(
        '#type' => 'operations',
        '#links' => $config_op,
      );
    }

    $form['actions'] = array('#type' => 'actions', '#weight' => 9);
    $form['actions']['submit'] = array(
      '#type' => 'submit',
      '#button_type' => 'primary',
      '#value' => $this->t('Save settings'),
    );

    return $form;
  }

  /**
   * Ajax callback for handling switching the bundle selector.
   */
  public function updateForm($form, FormStateInterface $form_state) {
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    $enabled_methods = array_filter($form_state->getValue('enabled'));
    ksort($enabled_methods);
    $method_weights = $form_state->getValue('weight');
    ksort($method_weights);

    $current_bundle = $this->assigner->getBundle();
    $old_name = $current_bundle->getMachineName();
    $new_name = $form_state->getValue(array('bundle', 'machine_name'));
    if ($old_name != $new_name) {
      $current_bundle = $this->assigner->renameBundle($old_name, $new_name);
    }
    $current_bundle->setName($form_state->getValue(array('bundle', 'name')));
    $current_bundle->setDescription($form_state->getValue(array('bundle', 'description')));
    $current_bundle->setEnabledAssignments(array_keys($enabled_methods));
    $current_bundle->setAssignmentWeights($method_weights);
    $current_bundle->setIsProfile($form_state->getValue(array('bundle', 'is_profile')));
    $current_bundle->setProfileName($form_state->getValue(array('bundle', 'profile_name')));
    $current_bundle->save();
    $this->assigner->setBundle($current_bundle);

    $form_state->setRedirect('features.assignment');
    drupal_set_message($this->t('Package assignment configuration saved.'));
  }

}
