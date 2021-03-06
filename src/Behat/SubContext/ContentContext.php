<?php
namespace Promet\Drupal\Behat\SubContext;

use Promet\Drupal\Behat\SubContext;

class ContentContext extends SubContext
{
    public $content;

    public static function getAlias() {
        return "DrupalContent";
    }

    /**
     * @Given /^(an|a|\d+) "([^"]*)" ([\w ]+) exist[s]?$/
     */
    public function createEntity($amount, $bundleLabel, $entityTypeLabel) {
        if (in_array($amount, array('an', 'a'))) {
            $amount = 1;
        }
        $entityTypeLabel = $this->removePluralFromLabel($entityTypeLabel);
        $entityType = $this->getEntityTypeFromLabel($entityTypeLabel);
        if (empty($entityType)) {
            throw new \Exception("Entity Type $entityTypeLabel doesn't exist.");
        }
        $bundle = $this->getEntityBundleFromLabel($bundleLabel, $entityType);
        $entityInfo = entity_get_info($entityType);
        for ($i=0; $i<$amount; $i++) {
            $entityObject = entity_create(
                $entityType,
                array($entityInfo['entity keys']['bundle'] => $bundle)
            );
            $wrapper = entity_metadata_wrapper($entityType, $entityObject);
            $wrapper->save();
            $this->content[$entityType][$bundle][$i] = $wrapper;
        }
    }

    public function getEntityTypeFromLabel($label) {
        $selectedEntityType = NULL;
        foreach (entity_get_info() as $entityType => $entityInfo) {
            if (strtolower($entityInfo['label']) == strtolower($label)) {
                $selectedEntityType = $entityType;
                break;
            }
        }
        return $selectedEntityType;
    }

    public function getEntityBundleFromLabel($label, $type) {
        $entityInfo = entity_get_info($type);
        $selectedBundle = NULL;
        foreach ($entityInfo['bundles'] as $bundleMachineName => $bundle){
            if (strtolower($bundle['label']) == strtolower($label)) {
                $selectedBundle = $bundleMachineName;
                break;
            }
        }
        return $selectedBundle;
    }

    public function removePluralFromLabel($label) {
        return preg_replace("/s$/", "", $label);
    }

    public function convertOrdinalToCardinalNumber($itemPositionOrdinal) {
        if ($itemPositionOrdinal == 'that' || $itemPositionOrdinal == 'those') {
            return FALSE;
        }
        $ordinal = array(
            'first'   => 1,
            'second'  => 2,
            'third'   => 3,
            'fourth'  => 4,
            'fifth'   => 5,
            'sixth'   => 6,
            'seventh' => 7,
            'eighth'  => 8,
            'ninth'   => 9,
            'tenth'   => 10,
        );
        foreach ($ordinal as $key => $value) {
            if ($key == $itemPositionOrdinal) {
                $cardinal = $value - 1;
                return $cardinal;
            }
        }
        throw new \Exception("Use from first to tenth ordinal numbers.");
    }

    /**
     * @Given /^(?:that|those) "([^"]*)" ([\w ]+) (?:has|have) "([^"]*)" set to "([^"]*)"$/
     */
    public function setEntitiesPropertyValue($bundleLabel, $entityTypeLabel, $fieldLabel, $rawValue) {
        $wrappers = $this->getWrappers($bundleLabel, $entityTypeLabel);
        foreach ($wrappers as $wrapper) {
            $this->setValue($wrapper, $fieldLabel, $rawValue);
        }
    }

    /**
     * @Given /^the ([\w ]+) "([^"]*)" ([\w ]+) (?:has|have) "([^"]*)" set to "([^"]*)"$/
     */
    public function setEntityPropertyValue($itemPositionOrdinal, $bundleLabel, $entityTypeLabel, $fieldLabel, $rawValue) {
        $itemPositionCardinal = $this->convertOrdinalToCardinalNumber($itemPositionOrdinal);
        $wrappers = $this->getWrappers($bundleLabel, $entityTypeLabel);
        if (isset($wrappers[$itemPositionCardinal])) {
            $wrapper = $wrappers[$itemPositionCardinal];
        }
        else {
            throw new \Exception("There is no $itemPositionOrdinal element.");
        }
        $this->setValue($wrapper, $fieldLabel, $rawValue);
    }


    public function getWrappers($bundleLabel, $entityTypeLabel) {
        $entityTypeLabel = $this->removePluralFromLabel($entityTypeLabel);
        $entityType = $this->getEntityTypeFromLabel($entityTypeLabel);
        if (empty($entityType)) {
            throw new \Exception("Entity Type $entityTypeLabel doesn't exist.");
        }
        $bundle = $this->getEntityBundleFromLabel($bundleLabel, $entityType);
        $mainContext = $this->getMainContext();
        $wrappers = $mainContext->getSubcontext('DrupalContent')->content[$entityType][$bundle];
        return $wrappers;
    }

    public function setValue($wrapper, $fieldLabel, $rawValue) {
        $subField = FALSE;
        if (strpos($fieldLabel,':') !== FALSE) {
            $fieldStrings = explode(':', $fieldLabel);
            $fieldLabel = $fieldStrings[0];
            $subField = $fieldStrings[1];
        }
        foreach ($wrapper->getPropertyInfo() as $key => $wrapper_property) {
            if ($fieldLabel == $wrapper_property['label']) {
                $fieldMachineName = $key;
                if (isset($wrapper_property['type']) && $wrapper_property['type'] == 'boolean') {
                    $value = filter_var($rawValue, FILTER_VALIDATE_BOOLEAN);
                }
                elseif ($fieldInfo = field_info_field($fieldMachineName)) {
                    // @todo add condition for each field type.
                    switch ($fieldInfo['type']) {
                        case 'entityreference':
                            $relatedEntityType = $fieldInfo['settings']['target_type'];
                            $targetBundles = $fieldInfo['settings']['handler_settings']['target_bundles'];
                            $query = new \EntityFieldQuery();
                            $query->entityCondition('entity_type', $relatedEntityType);

                            // Adding special conditions.
                            if (count($targetBundles) > 1) {
                                $relatedBundles = array_keys($targetBundles);
                                $query->entityCondition('bundle', $relatedBundles, 'IN');
                            }
                            else {
                                $relatedBundles = reset($targetBundles);
                                $query->entityCondition('bundle', $relatedBundles);
                            }
                            if ($relatedEntityType == 'node') {
                                $query->propertyCondition('title', $rawValue);
                            }
                            if ($relatedEntityType == 'taxonomy_term' || $relatedEntityType == 'user') {
                                $query->propertyCondition('name', $rawValue);
                            }
                            $results = $query->execute();
                            if (empty($results)) {
                                throw new \Exception("Entity that you want relate doesn't exist.");
                            }
                            $value = array_keys($results[$relatedEntityType]);
                            break;

                        case 'image':
                            try {
                                $fieldInstance = field_info_instance($wrapper->type(), $fieldMachineName, $wrapper->getBundle());
                                $fieldDirectory = 'public://' . $fieldInstance['settings']['file_directory'];
                                $image = file_get_contents($rawValue);
                                $pathinfo = pathinfo($rawValue);
                                $filename = $pathinfo['basename'];
                                file_prepare_directory($fieldDirectory, FILE_CREATE_DIRECTORY);
                                $value = (array) file_save_data($image, $fieldDirectory . '/' . $filename, FILE_EXISTS_RENAME);
                                if ($fieldInfo['cardinality'] > 1 || $fieldInfo['cardinality'] == FIELD_CARDINALITY_UNLIMITED) {
                                    $value = array($value);
                                }
                            }
                            catch (Exception $e) {
                                throw new \Exception("File $rawValue coundn't be saved.");
                            }
                            break;

                        default:
                            $value = $rawValue;
                            break;
                    }
                }
            }
        }
        if (empty($fieldMachineName)) {
            throw new \Exception("Entity property $fieldLabel doesn't exist.");
        }
        if ($subField) {
            $wrapper->$fieldMachineName->$subField = $value;
        }
        else {
            $wrapper->$fieldMachineName = $value;
        }
        $wrapper->save();
    }
}
