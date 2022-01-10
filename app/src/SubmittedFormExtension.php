<?php

use SilverStripe\Dev\Debug;
use SilverStripe\ORM\DataExtension;
use SilverStripe\UserForms\Model\EditableFormField\EditableFileField;

class SubmittedFormExtension extends DataExtension
{

    public function updateAfterProcess()
    {
        $submittedForm = $this->getOwner();

        foreach ($submittedForm->Parent()->Fields() as $field) {
            if (!empty($_FILES[$field->Name]['tmp_name'])
                && file_exists($_FILES[$field->Name]['tmp_name'])
            ) {
                @unlink($_FILES[$field->Name]['tmp_name']);
            }
        }
    }

}
