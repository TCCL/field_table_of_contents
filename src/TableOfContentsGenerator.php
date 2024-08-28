<?php

/**
 * TableOfContentsGenerator.php
 *
 * field_table_of_contents
 */

namespace Drupal\field_table_of_contents;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem;
use Drupal\Core\Render\Renderer;
use Drupal\paragraphs\Entity\Paragraph;

/**
 * Service that generates a table of contents structure.
 *
 * This generator works by iterating across the fields of a content entity. As
 * the generator iterates, it identifies headings to add to the table of
 * contents structure. (This can include nested headings as well.)
 *
 * Headings are identified in several different ways:
 *  1) By interpreting a field value as HTML and parsing for heading tags.
 *  2) By interpreting a field value as a heading directly.
 */
class TableOfContentsGenerator {
  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManager
   */
  private $entityTypeManager;

  /**
   * The core rendering service.
   *
   * @var \Drupal\Core\Render\Renderer
   */
  private $renderer;

  /**
   * The entity view display storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  private $storage;

  /**
   * In-memory cache.
   *
   * @var array
   */
  private $cache = [];

  /**
   * Creates a new TableOfContentsGenerator instance.
   *
   * @param \Drupal\Core\Entity\EntityTypeManager $entityTypeManager
   * @param \Drupal\Core\Render\Renderer $renderer
   */
  public function __construct(EntityTypeManager $entityTypeManager,Renderer $renderer) {
    $this->entityTypeManager = $entityTypeManager;
    $this->renderer = $renderer;

    $this->storage = $this->entityTypeManager->getStorage('entity_view_display');
  }

  /**
   * Looks up an existing, cached table of contents for the indicated node entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *  The entity used to key a cached table of contents.
   *
   * @return \Drupal\field_table_of_contents\TableOfContents
   *  Returns the table of contents if one exists or null if not found.
   */
  public function lookup(ContentEntityInterface $entity) : ?TableOfContents {
    $cacheKey = static::makeCacheKey($entity);
    return $this->cache[$cacheKey] ?? null;
  }

  /**
   * Generates a table of contents structure for the indicated entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *  The top-level entity for which to generate a table of contents.
   * @param array $settings
   *  Settings to apply that affect how the table of contents is generated.
   *  - field_types: List of field type machine names that are to be searched for
   *      headings.
   *  - scan_paragraphs: If true, then paragraph fields are also searched.
   *  - is_relative: If true, then the table of contents will contain relative
   *    hypertext.
   * @param bool $cache
   *  Determines if a cached table of contents may be looked up instead of
   *
   * @return \Drupal\field_table_of_contents\TableOfContents
   */
  public function generate(ContentEntityInterface $entity,array $settings = [],bool $cache = true) : TableOfContents {
    // Look up and return cached version if present and configured.
    $cacheKey = static::makeCachekey($entity);
    if ($cache && isset($this->cache[$cacheKey])) {
      return $this->cache[$cacheKey];
    }

    // Extract settings.

    $settings += [
      'field_types' => ['text_long','text_with_summary'],
      'heading_fields' => [],
      'scan_paragraphs' => true,
      'is_relative' => false,
    ];

    // Parse heading field structure.
    $headingFields = [];
    foreach ($settings['heading_fields'] as $repr) {
      list($type,$bundle,$fieldName) = explode(':',$repr);
      $headingFields[$type][$bundle][$fieldName] = true;
    }
    $settings['heading_fields'] = $headingFields;

    // Process the node and cache the result.
    $result = new TableOfContents($entity,$settings['is_relative']);
    $this->processEntity($result,$entity,$settings);

    return $result;
  }

  protected function processEntity(TableOfContents $toc,
                                   ContentEntityInterface $entity,
                                   array $settings) : void
  {
    // Extract settings.
    $fieldTypes = $settings['field_types'];
    $headingFields = $settings['heading_fields'];
    $scanParagraphs = $settings['scan_paragraphs'];

    // Get entity type and bundle.
    $type = $entity->getEntityTypeId();
    $bundle = $entity->bundle();

    // Sort fields using the default display configuration. This also will hide
    // any fields that were disabled.
    $fields = $entity->getFields();
    $storageId = "$type.$bundle.default";
    $viewDisplay = $this->storage->load($storageId);
    if ($viewDisplay) {
      $fs = $viewDisplay->getComponents();
      uasort($fs,function($a,$b) {
        return $a['weight'] - $b['weight'];
      });
      $fieldOrder = array_keys($fs);
    }
    else {
      $fieldOrder = array_keys($fields);
    }

    foreach ($fieldOrder as $fieldName) {
      if (!isset($fields[$fieldName])) {
        continue;
      }

      $itemList = $fields[$fieldName];
      foreach ($itemList as $delta => $fieldItem) {
        $fieldDef = $fieldItem->getFieldDefinition();
        $fieldType = $fieldDef->getType();

        // Never process a table of contents field (even if configured).
        if ($fieldType == 'tccl_table_of_contents') {
          continue;
        }

        // Process paragraph subfields if configured.
        if ($fieldItem instanceof EntityReferenceItem && $scanParagraphs) {
          if ($fieldItem->entity instanceof Paragraph) {
            $this->processEntity($toc,$fieldItem->entity,$settings);
            continue;
          }
        }

        // Check if the field is configured as a heading field.
        if (isset($headingFields[$type][$bundle][$fieldName])) {
          $this->processHeadingField($toc,$entity,$fieldName,$delta,$fieldItem);
        }

        // Only process fields having a configured field type.
        if (!in_array($fieldType,$fieldTypes)) {
          continue;
        }

        // Default case: try processing the field as HTML.
        $this->processHtml($toc,$entity,$fieldName,$delta,$fieldItem);
      }
    }

    // Cache the table by entity ID. Note that nested entities are also
    // recursively used as indeces as well.
    $this->cache[static::makeCacheKey($entity)] = $toc;
  }

  protected function processHtml(TableOfContents $toc,
                                 ContentEntityInterface $entity,
                                 string $fieldName,
                                 int $delta,
                                 FieldItemInterface $fieldItem) : void
  {
    // Evaluate the field as HTML by rendering it. NOTE: for now, we always use
    // the 'full' view mode.
    $render = $fieldItem->view('full');
    $html = $this->renderer->renderRoot($render);
    if (empty($html)) {
      return;
    }

    // Parse HTML and pull out header nodes for evaluation.

    $dom = Html::load($html);
    $xpath = new \DOMXpath($dom);
    $expr = [];
    for ($n = 2;$n <= 4;++$n) {
      $sel = "h$n";
      $expr[] = "//$sel";
    }

    $expr = implode(' | ',$expr);
    $nodes = $xpath->query($expr);

    // Add headings based on header nodes. NOTE: we do not process <h1> since
    // this is commonly used in Drupal for page titles only.
    $n = 0;
    foreach ($nodes as $node) {
      $label = trim($node->nodeValue,"\xc2\xa0 \t\n\r\0\v");
      if (empty($label)) {
        continue;
      }

      $idAttr = $node->attributes->getNamedItem('id');
      if (isset($idAttr)) {
        $id = $idAttr->nodeValue;
      }
      else {
        $id = static::generateId($label);

        if ($node->parentNode) {
          // Inject an anchor element for referencing this heading via the ID.
          $anchor = $dom->createElement('a');
          $anchor->setAttribute('id',$id);
          $node->parentNode->insertBefore($anchor,$node);
        }
      }

      if (preg_match('/h([2-9])/',$node->nodeName,$matches)) {
        $level = (int)$matches[1] - 2;
      }
      else {
        $level = 0;
      }

      $toc->addHeading($label,$id,$level);
      $n += 1;
    }

    // Set field info if we added at least one heading. This will replace the
    // field content with the saved representation of the DOM document.
    if ($n > 0) {
      $toc->setFieldInfo($entity,$fieldName,$delta,$dom);
    }
  }

  protected function processHeadingField(TableOfContents $toc,
                                         ContentEntityInterface $entity,
                                         string $fieldName,
                                         int $delta,
                                         FieldItemInterface $fieldItem) : void
  {
    $text = trim($fieldItem->getString(),"\xc2\xa0 \t\n\r\0\v");
    $text = substr($text,0,128);
    $id = static::generateId($text);
    $toc->addHeading($text,$id);
    $toc->setFieldInfo($entity,$fieldName,$delta,$id);
  }

  protected static function generateId(string $label) : string {
    return preg_replace('/[^0-9a-zA-Z\.]+/','-',$label);
  }

  protected static function makeCacheKey(ContentEntityInterface $entity) : string {
    $id = $entity->id();
    $type = $entity->getEntityTypeId();
    return "$type:$id";
  }
}
