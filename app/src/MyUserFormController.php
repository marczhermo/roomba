<?php


use Psr\Log\LoggerInterface;
use SilverStripe\AssetAdmin\Controller\AssetAdmin;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Storage\AssetContainer;
use SilverStripe\Assets\Upload;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Email\Email;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\Form;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\ValidationException;
use SilverStripe\ORM\ValidationResult;
use SilverStripe\Security\Security;
use SilverStripe\UserForms\Control\UserDefinedFormController;
use SilverStripe\UserForms\Extension\UserFormFileExtension;
use SilverStripe\UserForms\Model\EditableFormField\EditableFileField;
use SilverStripe\UserForms\Model\Submission\SubmittedFileField;
use SilverStripe\UserForms\Model\Submission\SubmittedForm;
use SilverStripe\View\SSViewer;

class MyUserFormController extends UserDefinedFormController
{
    private static $finished_anchor = '#uff';

    private static $allowed_actions = [
        'index',
        'ping',
        'Form',
        'finished',
    ];

    /** @var string The name of the folder where form submissions will be placed by default */
    private static $form_submissions_folder = 'Form-submissions';

    /**
     * Process the form that is submitted through the site
     * {@see UserForm::validate()} for validation step prior to processing
     *
     * @param array $data
     * @param Form  $form
     *
     * @return HTTPResponse
     * @throws ValidationException
     */
    public function process($data, $form)
    {
        $submittedForm = SubmittedForm::create();
        $submittedForm->SubmittedByID = Security::getCurrentUser() ? Security::getCurrentUser()->ID : 0;
        $submittedForm->ParentClass = get_class($this->data());
        $submittedForm->ParentID = $this->ID;

        // if saving is not disabled save now to generate the ID
        if (!$this->DisableSaveSubmissions) {
            $submittedForm->write();
        }

        $attachments = [];
        $submittedFields = ArrayList::create();

        foreach ($this->data()->Fields() as $field) {
            if (!$field->showInReports()) {
                continue;
            }

            $submittedField = $field->getSubmittedFormField();
            $submittedField->ParentID = $submittedForm->ID;
            $submittedField->Name = $field->Name;
            $submittedField->Title = $field->getField('Title');

            // save the value from the data
            if ($field->hasMethod('getValueFromData')) {
                $submittedField->Value = $field->getValueFromData($data);
            } else {
                if (isset($data[$field->Name])) {
                    $submittedField->Value = $data[$field->Name];
                }
            }

            if (!empty($data[$field->Name])) {
                if (in_array(EditableFileField::class, $field->getClassAncestry())) {
                    if (!empty($_FILES[$field->Name]['name'])) {
                        $foldername = $field->getFormField()->getFolderName();

                        // create the file from post data
                        $upload = Upload::create();
                        try {
                            $upload->loadIntoFile($_FILES[$field->Name], null, $foldername);
                        } catch (ValidationException $e) {
                            $validationResult = $e->getResult();
                            foreach ($validationResult->getMessages() as $message) {
                                $form->sessionMessage($message['message'], ValidationResult::TYPE_ERROR);
                            }
                            Controller::curr()->redirectBack();
                            return;
                        }
                        /** @var AssetContainer|File $file */
                        $file = $upload->getFile();
                        $file->ShowInSearch = 0;
                        //$file->UserFormUpload = UserFormFileExtension::USER_FORM_UPLOAD_TRUE;
                        $file->UserFormUpload = UserFormFileExtension::USER_FORM_UPLOAD_FALSE;
                        $file->write();

                        // generate image thumbnail to show in asset-admin
                        // you can run userforms without asset-admin, so need to ensure asset-admin is installed
                        if (class_exists(AssetAdmin::class)) {
                            AssetAdmin::singleton()->generateThumbnails($file);
                        }

                        // write file to form field
                        $submittedField->UploadedFileID = $file->ID;

                        // attach a file only if lower than 1MB
                        if ($file->getAbsoluteSize() < 1024 * 1024 * 1) {
                            $attachments[] = $file;
                        }
                    }
                }
            }

            $submittedField->extend('onPopulationFromField', $field);

            if (!$this->DisableSaveSubmissions) {
                $submittedField->write();
            }

            $submittedFields->push($submittedField);
        }

        $emailData = [
            'Sender' => Security::getCurrentUser(),
            'HideFormData' => false,
            'Fields' => $submittedFields,
            'Body' => '',
        ];

        $this->extend('updateEmailData', $emailData, $attachments);

        // email users on submit.
        if ($recipients = $this->FilteredEmailRecipients($data, $form)) {
            foreach ($recipients as $recipient) {
                $email = Email::create()
                    ->setHTMLTemplate('email/SubmittedFormEmail')
                    ->setPlainTemplate('email/SubmittedFormEmailPlain');

                // Merge fields are used for CMS authors to reference specific form fields in email content
                $mergeFields = $this->getMergeFieldsMap($emailData['Fields']);

                if ($attachments && (bool) $recipient->HideFormData === false) {
                    foreach ($attachments as $file) {
                        /** @var File $file */
                        if ((int) $file->ID === 0) {
                            continue;
                        }

                        $email->addAttachmentFromData(
                            $file->getString(),
                            $file->getFilename(),
                            $file->getMimeType()
                        );
                    }
                }

                if (!$recipient->SendPlain && $recipient->emailTemplateExists()) {
                    $email->setHTMLTemplate($recipient->EmailTemplate);
                }

                // Add specific template data for the current recipient
                $emailData['HideFormData'] =  (bool) $recipient->HideFormData;
                // Include any parsed merge field references from the CMS editor - this is already escaped
                // This string substitution works for both HTML and plain text emails.
                // $recipient->getEmailBodyContent() will retrieve the relevant version of the email
                $emailData['Body'] = SSViewer::execute_string($recipient->getEmailBodyContent(), $mergeFields);

                // Push the template data to the Email's data
                foreach ($emailData as $key => $value) {
                    $email->addData($key, $value);
                }

                // check to see if they are a dynamic reply to. eg based on a email field a user selected
                $emailFrom = $recipient->SendEmailFromField();
                if ($emailFrom && $emailFrom->exists()) {
                    $submittedFormField = $submittedFields->find('Name', $recipient->SendEmailFromField()->Name);

                    if ($submittedFormField && $submittedFormField->Value && is_string($submittedFormField->Value)) {
                        $email->setReplyTo(explode(',', $submittedFormField->Value));
                    }
                } elseif ($recipient->EmailReplyTo) {
                    $email->setReplyTo(explode(',', $recipient->EmailReplyTo));
                }

                // check for a specified from; otherwise fall back to server defaults
                if ($recipient->EmailFrom) {
                    $email->setFrom(explode(',', $recipient->EmailFrom));
                }

                // check to see if they are a dynamic reciever eg based on a dropdown field a user selected
                $emailTo = $recipient->SendEmailToField();

                try {
                    if ($emailTo && $emailTo->exists()) {
                        $submittedFormField = $submittedFields->find('Name', $recipient->SendEmailToField()->Name);

                        if ($submittedFormField && is_string($submittedFormField->Value)) {
                            $email->setTo(explode(',', $submittedFormField->Value));
                        } else {
                            $email->setTo(explode(',', $recipient->EmailAddress));
                        }
                    } else {
                        $email->setTo(explode(',', $recipient->EmailAddress));
                    }
                } catch (Swift_RfcComplianceException $e) {
                    // The sending address is empty and/or invalid. Log and skip sending.
                    $error = sprintf(
                        'Failed to set sender for userform submission %s: %s',
                        $submittedForm->ID,
                        $e->getMessage()
                    );

                    Injector::inst()->get(LoggerInterface::class)->notice($error);

                    continue;
                }

                // check to see if there is a dynamic subject
                $emailSubject = $recipient->SendEmailSubjectField();
                if ($emailSubject && $emailSubject->exists()) {
                    $submittedFormField = $submittedFields->find('Name', $recipient->SendEmailSubjectField()->Name);

                    if ($submittedFormField && trim($submittedFormField->Value)) {
                        $email->setSubject($submittedFormField->Value);
                    } else {
                        $email->setSubject(SSViewer::execute_string($recipient->EmailSubject, $mergeFields));
                    }
                } else {
                    $email->setSubject(SSViewer::execute_string($recipient->EmailSubject, $mergeFields));
                }

                $this->extend('updateEmail', $email, $recipient, $emailData);

                if ((bool)$recipient->SendPlain) {
                    // decode previously encoded html tags because the email is being sent as text/plain
                    $body = html_entity_decode($emailData['Body']) . "\n";
                    if (isset($emailData['Fields']) && !$emailData['HideFormData']) {
                        foreach ($emailData['Fields'] as $field) {
                            if ($field instanceof SubmittedFileField) {
                                $body .= $field->Title . ': ' . $field->ExportValue ." \n";
                            } else {
                                $body .= $field->Title . ': ' . $field->Value . " \n";
                            }
                        }
                    }

                    $email->setBody($body);

                    try {
                        $email->sendPlain();
                    } catch (Exception $e) {
                        Injector::inst()->get(LoggerInterface::class)->error($e);
                    }
                } else {
                    try {
                        $email->send();
                    } catch (Exception $e) {
                        Injector::inst()->get(LoggerInterface::class)->error($e);
                    }
                }
            }
        }

        if ($this->DisableSaveSubmissions) {
            foreach ($attachments as $file) {
                /** @var File $file */
                if (!$file->ID) {
                    continue;
                }

                $file->deleteFile();
                $file->doArchive();
            }
        }

        $submittedForm->extend('updateAfterProcess');

        $session = $this->getRequest()->getSession();
        $session->clear("FormInfo.{$form->FormName()}.errors");
        $session->clear("FormInfo.{$form->FormName()}.data");

        $referrer = (isset($data['Referrer'])) ? '?referrer=' . urlencode($data['Referrer']) : "";

        // set a session variable from the security ID to stop people accessing
        // the finished method directly.
        if (!$this->DisableAuthenicatedFinishAction) {
            if (isset($data['SecurityID'])) {
                $session->set('FormProcessed', $data['SecurityID']);
            } else {
                // if the form has had tokens disabled we still need to set FormProcessed
                // to allow us to get through the finshed method
                if (!$this->Form()->getSecurityToken()->isEnabled()) {
                    $randNum = rand(1, 1000);
                    $randHash = md5($randNum);
                    $session->set('FormProcessed', $randHash);
                    $session->set('FormProcessedNum', $randNum);
                }
            }
        }

        if (!$this->DisableSaveSubmissions) {
            $session->set('userformssubmission'. $this->ID, $submittedForm->ID);
        }

        return $this->redirect($this->Link('finished') . $referrer . $this->config()->get('finished_anchor'));
    }

}
