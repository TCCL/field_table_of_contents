<?php

/**
 * TableOfContentsFieldFormatter.php
 *
 * field_table_of_contents
 */

namespace Drupal\field_table_of_contents\Plugin\Field\FieldFormatter;

use Drupal\paragraphs\ParagraphInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\Entity\Node;

/**
 * Provides a field formatter for rendering a table of contents.
 *
 * @FieldFormatter(
 *  id = "tccl_table_of_contents",
 *  label = "Table of Contents",
 *  field_types = {
 *    "tccl_table_of_contents"
 *  }
 * )
 */
class TableOfContentsFieldFormatter extends FormatterBase {
  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    $settings = parent::defaultSettings();

    $settings += [
      // List of field types that are searched for headings.
      'field_types' => [
        'text_long',
        'text_with_summary',
      ],

      // A list of "bundle:field_name" strings that are interpreted as a pure
      // heading. The type of these fields need not exist in 'field_types'.
      'heading_fields' => [],

      // If a 'paragraph' entity revision field is found, the table of contents
      // will recurse through any child paragraph fields.
      'scan_paragraphs' => true,

      // If the field targets another page, then the title element will link to
      // this page.
      'hyperlink_title' => true,
    ];

    return $settings;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form,FormStateInterface $form_state) {
    $fieldTypePluginManager = \Drupal::service('plugin.manager.field.field_type');
    $fieldTypeOptions = [];
    foreach ($fieldTypePluginManager->getDefinitions() as $name => $info) {
      $fieldTypeOptions[$name] = $info['label'] ?? $name;
    }

    $form['field_types'] = [
      '#type' => 'select',
      '#title' => $this->t('Supported Field Types'),
      '#default_value' => $this->getSetting('field_types'),
      '#description' => $this->t(
        'One or more field type IDs that are searched for headings from which '
        .'to generate the table of contents.'
      ),
      '#options' => $fieldTypeOptions,
      '#multiple' => true,
      '#sort_options' => true,
      '#attributes' => [
        'style' => 'width: 70ch;height: 20em',
      ],
    ];

    $bundleService = \Drupal::service('entity_type.bundle.info');
    $bundles = $bundleService->getBundleInfo('node');

    $entityFieldManager = \Drupal::service('entity_field.manager');
    $moduleHandler = \Drupal::service('module_handler');
    $headingFieldOptions = [];
    foreach ($bundles as $bundle => $bundleInfo) {
      $innerOptions = [];

      foreach ($entityFieldManager->getFieldDefinitions('node',$bundle)
               as $fieldName => $def)
      {
        if (substr($fieldName,0,strlen('field_')) != 'field_') {
          continue;
        }

        $innerOptions["node:$bundle:$fieldName"] = $def->getLabel();
      }

      if (!empty($innerOptions)) {
        $headingFieldOptions[$bundleInfo['label'] ?? $bundle] = $innerOptions;
      }
    }
    if ($moduleHandler->moduleExists('paragraphs')) {
      $paragraphBundles = $bundleService->getBundleInfo('paragraph');
      foreach ($paragraphBundles as $bundle => $bundleInfo) {
        $defs = $entityFieldManager->getFieldDefinitions('paragraph',$bundle);
        foreach ($defs as $fieldName => $def) {
          if (substr($fieldName,0,strlen('field_')) != 'field_') {
            continue;
          }

          $label = "Paragraphs Module ({$bundleInfo['label']})";
          $headingFieldOptions[$label]["paragraph:$bundle:$fieldName"] = $def->getLabel();
        }
      }
    }

    $form['heading_fields'] = [
      '#type' => 'select',
      '#title' => $this->t('Heading Fields'),
      '#default_value' => $this->getSetting('heading_fields'),
      '#description' => $this->t(
        'Zero or more field names that are to be interpreted as headings. '
        .'Note that the field type is not considered for heading fields.'
      ),
      '#options' => $headingFieldOptions,
      '#multiple' => true,
      '#sort_options' => true,
      '#attributes' => [
        'style' => 'width: 70ch;height: 20em',
      ],
    ];

    $form['scan_paragraphs'] = [
      '#type' => 'checkbox',
      '#return_value' => 1,
      '#title' => $this->t('Scan Paragraphs'),
      '#default_value' => $this->getSetting('scan_paragraphs'),
      '#description' => $this->t(
        'Toggles scanning of Paragraph field instances when generating the '
        .'table of contents.'
      ),
    ];

    $form['hyperlink_title'] = [
      '#type' => 'checkbox',
      '#return_value' => 1,
      '#title' => $this->t('Hyperlink Page Titles'),
      '#default_value' => $this->getSetting('hyperlink_title'),
      '#description' => $this->t(
        'If the table of contents field targets another page, then the '
        .'page title element will link to that page if enabled.'
      )
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items,$langcode = null) {
    $generator = \Drupal::service('field_table_of_contents.generator');
    $parentEntity = $items->getEntity();

    while ($parentEntity instanceof ParagraphInterface) {
      /** @var \Drupal\paragraphs\ParagraphInterface $parentEntity */
      $parentEntity = $parentEntity->getParentEntity();
    }

    // Prepare generator settings from formatter settings.
    $settings = [
      'field_types' => $this->getSetting('field_types'),
      'heading_fields' => $this->getSetting('heading_fields'),
      'scan_paragraphs' => (bool)$this->getSetting('scan_paragraphs'),
    ];

    $hyperlinkTitle = (bool)$this->getSetting('hyperlink_title');

    $elements = [];
    foreach ($items as $delta => $item) {
      $title = $item->title;

      // Determine which content entity is to be used to generate the table of
      // contents. This is either the one assigned to the table of contents
      // field or the parent entity if none was assigned.
      if (isset($item->nid)) {
        $entity = Node::load($item->nid);
        $settings['is_relative'] = false;

        if ($hyperlinkTitle) {
          $title = [
            '#type' => 'link',
            '#title' => $title,
            '#url' => $entity->toUrl(),
          ];
        }
      }
      else if ($parentEntity instanceof ContentEntityInterface) {
        $entity = $parentEntity;
        $settings['is_relative'] = true;
      }
      else {
        $elements[$delta] = [
          '#markup' => '<b>Cannot render table of contents in non-node content entity.</b>',
        ];
        continue;
      }

      // Render the table of contents.
      $toc = $generator->generate($entity,$settings);
      $render = $toc->toRenderArray();

      // Apply any remaining proerties.
      $render += [
        '#title' => $title,
      ];

      $elements[$delta] = $render;
    }

    return $elements;
  }
}
