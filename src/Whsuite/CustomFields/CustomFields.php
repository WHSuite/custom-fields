<?php

namespace Whsuite\CustomFields;

use Whsuite\Forms\Forms;
use Whsuite\Translation\Translation;

/**
* Custom Fields
*
* The custom fields package handles the custom fields, specifically building forms,
* validating input, getting individual fields, setting individual fields, etc.
*
* @package  WHSuite-Package-CustomFields
* @author  WHSuite Dev Team <info@whsuite.com>
* @copyright  Copyright (c) 2014, Turn 24 Ltd.
* @license  http://whsuite.com/license/ The WHSuite License Agreement
* @link  http://whsuite.com
* @since  Version 1.0
*/
class CustomFields
{
    /**
     * Get Group
     *
     * Compiles a group, its fields and field values into an array.
     *
     * @param string $slug The group's unique slug.
     * @param int $model_id The record ID to load the results for (defaults to zero).
     * @param bool $is_client If the user is a client, specify here so we only show them fields they are allowed to see.
     *
     * @return array Returns an array contining the group, fields and field values.
     */
    public function getGroup($slug, $model_id = 0, $is_client = true)
    {
        // Load the group
        $group = \DataGroup::where('slug', '=', $slug)->first();
        if (!$group) {
            return false;
        }

        // Create an array to store all the group data in.
        $group_data = $group->toArray();

        // Load the fields
        if ($is_client) {
            $fields = $group->DataField()->where('is_staff_only', '=', '0')->get();
        } else {
            $fields = $group->DataField()->get();
        }

        foreach ($fields as $field) {
            $group_data['fields'][$field->slug] = $field->toArray();

            // Get field value for the current model_id value
            $values = $field->DataFieldValue()->where('model_id', '=', $model_id)->first();

            if ($values) {
                $group_data['fields'][$field->slug]['value'] = $values->toArray();
            } else {
                // No value row was found, so set an empty set of values

                $group_data['fields'][$field->slug]['value'] = array(
                    'id' => null,
                    'data_field_id' => null,
                    'model_id' => null,
                    'value' => null,
                );
            }
        }

        return $group_data;
    }

    /**
     * Generate Form
     *
     * Generates the form to show when editing custom fields.
     *
     * @param string $slug The group's unique slug.
     * @param int $model_id The record ID to load the results for (defaults to zero).
     * @param bool $is_client If the user is a client, specify here so we only show them fields they are allowed to see.
     *
     * @return string Returns the HTML for the form fields.
     */
    public function generateForm($slug, $model_id = 0, $is_client = true)
    {
        $form = '';

        $group_data = $this->getGroup($slug, $model_id, $is_client);
        if (!$group_data || count($group_data) < 1) {
            return $form;
        }

        $forms = Forms::init();
        $lang = \App::get('translation');

        if (!isset($group_data['fields']) || count($group_data['fields']) < 1) {
            return $form;
        }

        foreach ($group_data['fields'] as $field) {
            // Decrypt the value of the field and add it back into the value object

            if (isset($field['value']) && $field['value']['value'] != '') {
                $field['value']['value'] = \App::get('security')->decrypt($field['value']['value']);
            }

            // Modify the slug to include a custom field string, so we can keep
            // the fields unique if they are going onto an existing form with other data.
            $field['slug'] = 'CustomFields.'.$field['slug'];

            $extra_params = array();

            if ($field['is_editable'] == '0' && DEV_MODE == false) {
                $extra_params['disabled'] = 'disabled';
            }

            if ($field['type'] == 'text') {
                $form .= $forms->input($field['slug'], $lang->get($field['title']), array('value' => $field['value']['value'], 'placeholder' => $field['placeholder'])+$extra_params);
            } elseif ($field['type'] == 'select') {
                // Convert the select options back to an array from json

                $options = json_decode($field['value_options']);

                $form .= $forms->select($field['slug'], $lang->get($field['title']), array('value' => $field['value']['value'], 'placeholder' => $field['placeholder'], 'options' => $options)+$extra_params);
            } elseif ($field['type'] == 'textarea') {
                $form .= $forms->textarea($field['slug'], $lang->get($field['title']), array('value' => $field['value']['value'], 'placeholder' => $field['placeholder'])+$extra_params);
            } elseif ($field['type'] == 'checkbox') {
                $checked = array();
                if ($field['value']['value'] == '1') {
                    $checked = array('checked' => 'checked');
                }
                $form .= $forms->checkbox($field['slug'], $lang->get($field['title']), $checked+$extra_params);
            } elseif ($field['type'] == 'wysiwyg') {
                $form .= $forms->wysiwyg($field['slug'], $lang->get($field['title']), array('value' => $field['value']['value'], 'placeholder' => $field['placeholder'])+$extra_params);
            }

            // Add the help text block if it's got anything inside it.
            if ($field['help_text'] !='') {
                $form .='<span class="help-block">'.$lang->get($field['help_text']).'</span>';
            }
        }

        return $form;
    }

    /**
     * Validate Custom Fields
     *
     * Runs the values posted by a custom field form through their individual
     * validation rules.
     *
     * @param string $slug The group's unique slug.
     * @param int $model_id The record ID to load the results for (defaults to zero).
     * @param bool $is_client If the user is a client, specify here so we only show them fields they are allowed to see.
     *
     * @return array Returns an array containing the result (true = valid) and an array of validation errors (if any).
     */
    public function validateCustomFields($slug, $model_id, $is_client)
    {
        $post_data = \Whsuite\Inputs\Post::get('CustomFields');

        $group_data = $this->getGroup($slug, $model_id, $is_client);

        if (count($group_data) < 1) {
            return array(
                'result' => true,
                'errors' => null
            );
        }

        // Build a validation rule table
        $rules = array();
        if (!isset($group_data['fields']) ||count($group_data['fields']) < 1) {
            return array(
                'result' => true,
                'errors' => null
            );
        }

        foreach ($group_data['fields'] as $field) {
            if (! empty($field['custom_regex'])) {
                $validation_rules = explode("|", $field['validation_rules']);

                $validation_rules[] = 'regex:'.$field['custom_regex'];

                $rules[$field['slug']] = $validation_rules;

            } elseif (! empty($field['validation_rules'])) {
                $rules[$field['slug']] = $field['validation_rules'];
            }
        }

        if (! empty($rules)) {
            // We've now got a validation table list. Now lets actually run the validation checker.
            $validator = new \Whsuite\Validator\Validator();
            $this->validator = $validator->init(DEFAULT_LANG);

            $validator = $this->validator->make($post_data, $rules);

            if (! $validator->fails()) {
                $result = true;
                $errors = null;
            } else {
                $result = false;
                $errors = $validator->messages();
            }
        } else {
            $result = true;
            $errors = null;
        }

        return array(
            'result' => $result,
            'errors' => $errors
        );
    }

    /**
     * Delete Custom Field Values
     *
     * Deletes the values of a custom field. This is used, if for example you
     * deleted a record that has custom fields. So if you deleted client id '1'
     * we'd want to also delete all their custom field records.
     *
     * @param string $slug The group's unique slug.
     * @param int $model_id The record ID to delete the values for (defaults to zero).
     *
     * @return bool Returns true if the deleting was successful.
     */
    public function deleteCustomFieldValues($slug, $model_id = 0)
    {
        $group_data = $this->getGroup($slug, $model_id, false);

        if (isset($group_data['fields'])) {
            foreach ($group_data['fields'] as $field) {
                if (isset($field['value']['id']) && $field['value']['id'] > 0) {
                    $value = \DataFieldValue::find($field['value']['id']);
                    $value->delete();
                }
            }
        }
    }

    /**
     * Save Custom Fields
     *
     * Saves the custom field records based on their updated values from a form.
     *
     * @param string $slug The group's unique slug.
     * @param int $model_id The record ID to load the results for (defaults to zero).
     * @param bool $is_client If the user is a client, specify here so we only show them fields they are allowed to see.
     *
     * @return bool Returns true if the saving was successful.
     */
    public function saveCustomFields($slug, $model_id = 0, $is_client = true)
    {
        $post_data = \Whsuite\Inputs\Post::get('CustomFields');

        $group_data = $this->getGroup($slug, $model_id, $is_client);
        if (!isset($group_data['fields']) || count($group_data['fields']) < 1) {
            return true;
        }

        foreach ($group_data['fields'] as $field) {
            if (isset($post_data[$field['slug']])) {
                if ($field['is_editable'] == '0' && DEV_MODE == false) {
                    continue;
                }

                $db_field = \DataFieldValue::where('data_field_id', '=', $field['id'])->where('model_id', '=', $model_id)->first();
                if (empty($db_field)) {
                    // The value row doesn't exist yet, so lets create it.

                    $db_field = new \DataFieldValue();
                    $db_field->data_field_id = $field['id'];
                    $db_field->model_id = $model_id;
                }

                $db_field->value = \App::get('security')->encrypt($post_data[$field['slug']]);

                if (!$db_field->save()) {
                    // Stop if any of the records fail to save.
                    return false;
                }
            }
        }

        // If we got this far the record were saved.
        return true;
    }

    /**
     * Get Field Value
     *
     * Gets the value of a field.
     *
     * @param string $group_slug The group's unique slug.
     * @param string $field_slug The field's slug.
     * @param int $model_id The record ID to load the result for (defaults to zero).
     *
     * @return string Returns the value of the field or null if it cant be found.
     */
    public function getFieldValue($group_slug, $field_slug, $model_id = 0)
    {
        // Load the group
        $group = \DataGroup::where('slug', '=', $group_slug)->first();

        if ($group) {
            // Load the field
            $field = $group->DataField()->where('slug', '=', $field_slug)->first();

            if ($field) {
                // Load the value
                $value = $field->DataFieldValue()->where('model_id', '=', $model_id)->first();

                if ($value) {
                    return \App::get('security')->decrypt($value->value);
                }
            }
        }

        return null;
    }

    /**
     * Set Field Value
     *
     * Sets the value of a field.
     *
     * @param string $new_value The new value to save.
     * @param string $group_slug The group's unique slug.
     * @param string $field_slug The field's slug.
     * @param int $model_id The record ID to load the result for (defaults to zero).
     *
     * @return string Returns true if the saving was successful.
     */
    public function setFieldValue($new_value, $group_slug, $field_slug, $model_id = 0)
    {
        // Load the group
        $group = \DataGroup::where('slug', '=', $group_slug)->first();

        if ($group) {
            // Load the field

            $field = $group->DataField()->where('slug', '=', $field_slug)->first();

            if ($field) {
                if ($field['is_editable'] == '0' && DEV_MODE == false) {
                    return false;
                }

                // Load the value
                $value = $field->DataFieldValue()->where('model_id', '=', $model_id)->first();

                if ($value) {
                    $value->value = \App::get('security')->encrypt($new_value);
                } else {
                    $value = new \DataFieldValue();
                    $value->data_field_id = $field->id;
                    $value->model_id = $model_id;
                    $value->value = \App::get('security')->encrypt($new_value);
                }

                return $value->save();
            }
        }

        return null;
    }
}
