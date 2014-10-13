<?php

    /**
     * A UserDefinedForm Page object with extra fields for payments
     *
     * @package userforms-payments
     */
    class UserDefinedPaymentForm extends UserDefinedForm
    {
        private static $db = array(
            "PaymentGateway"         => "Varchar",
            "PaymentCurrency"        => "Varchar(3)",
            "PaymentFields_Card"     => "Boolean",
            "PaymentFields_Billing"  => "Boolean",
            "PaymentFields_Shipping" => "Boolean",
            "PaymentFields_Company"  => "Boolean",
            "PaymentFields_Email"    => "Boolean",
            "OnErrorMessage"         => "HTMLText",
        );

        private static $has_one = array(
            "PaymentAmountField" => "EditableFormField",
        );

        private static $defaults = array(
            "PaymentCurrency"   => "AUD",
            "OnErrorMessage"    => "<p>Sorry, your payment could not be processed. Your credit card has not been charged. Please try again.</p>",
            "OnCompleteMessage" => "<p>Thank you. Your payment of [amount] has been processed.</p>"
        );

        public function getCMSFields()
        {
            $fields       = parent::getCMSFields();
            $gateways     = GatewayInfo::get_supported_gateways();
            $amountfields = $this->Fields()->map("ID", "Title");
            $fields->addFieldsToTab("Root.Payment",
                array(
                    DropdownField::create("PaymentAmountFieldID", "Payment Amount Field", $amountfields)->setDescription("This must return a value like 20.00 (no dollar sign)"),
                    new DropdownField("PaymentGateway", "Payment Gateway", $gateways),
                    new TextField("PaymentCurrency", "Payment Currency"),
                    new CheckboxField("PaymentFields_Card", "Show Card Fields"),
                    new CheckboxField("PaymentFields_Billing", "Show Billing Fields"),
                    new CheckboxField("PaymentFields_Shipping", "Show Shipping Fields"),
                    new CheckboxField("PaymentFields_Company", "Show Company Fields"),
                    new CheckboxField("PaymentFields_Email", "Show Email Fields")
                )
            );

            // text to show on error
            $onErrorFieldSet = new CompositeField(
                $label = new LabelField('OnErrorMessageLabel', _t('UserDefinedForm.ONERRORLABEL', 'Show on error')),
                $editor = new HtmlEditorField("OnErrorMessage", "", _t('UserDefinedForm.ONERRORMESSAGE', $this->OnErrorMessage))
            );

            $onErrorFieldSet->addExtraClass('field');
            $fields->insertAfter($onErrorFieldSet, "OnCompleteMessage");

            return $fields;
        }
    }

    class UserDefinedPaymentForm_Controller extends UserDefinedForm_Controller
    {

        private static $allowed_actions = array(
            "index",
            "ping",
            "Form",
            "finished",
            "complete",
            "error"
        );

        /**
         * Find all the omnipay fields that have been defined for this particular payment page
         *
         * @return array
         */
        private function getPaymentFieldsGroupArray()
        {
            $fields  = array();
            $options = array("Card", "Billing", "Shipping", "Company", "Email");

            foreach ($options as $option) {
                $dbfield = "PaymentFields_" . $option;
                if ($this->data()->$dbfield)
                    $fields[] = $option;
            }

            return $fields;
        }

        /**
         * Combine all the parent UserDefinedForm fields and the omnipay fields
         *
         * @return FieldList
         */
        public function getFormFields()
        {
            $gateway     = $this->data()->PaymentGateway;
            $fieldgroups = $this->getPaymentFieldsGroupArray();
            $factory     = new GatewayFieldsFactory($gateway, $fieldgroups);
            $fields      = parent::getFormFields();
            $fields->add(CompositeField::create($factory->getFields())->addExtraClass($gateway . "_fields"));

            if ($address1 = $fields->fieldByName('billingAddress1')) $address1->setTitle("Address Line 1");
            if ($address2 = $fields->fieldByName('billingAddress2')) $address2->setTitle("Address Line 2");

            return $fields;
        }

        /**
         * Make sure all omnipay fields are required
         *
         * @todo: make this more flexible
         *
         * @return RequiredFields
         */
        public function getRequiredFields()
        {
            $required    = parent::getRequiredFields();
            $gateway     = $this->data()->PaymentGateway;
            $fieldgroups = $this->getPaymentFieldsGroupArray();
            $factory     = new GatewayFieldsFactory($gateway, $fieldgroups);
            $fields      = $factory->getFields();
            foreach ($fields as $field) {
                if (!$field->hasMethod('getName')) continue;
                $fieldname = $field->getName();
                if ($fieldname == "billingAddress2") continue;
                $required->addRequiredField($fieldname);
            }

            $paymentfieldname = $this->PaymentAmountField()->Name;
            $required->addRequiredField($paymentfieldname);
            return $required;
        }


        /**
         * Process the form that is submitted through the site. Note that omnipay fields are NOT saved to the database.
         * This is intentional (so we don't save credit card details) but should be fixed in future, so we save all fields,
         * but only save the last 3 digits of the credit card (and not the CVV/exp date)
         *
         * @todo: save all fields to database except credit card fields
         *
         * @param array $data
         * @param Form  $form
         *
         * @return Redirection
         */
        public function process($data, $form)
        {
            Session::set("FormInfo.{$form->FormName()}.data", $data);
            Session::clear("FormInfo.{$form->FormName()}.errors");

            foreach ($this->Fields() as $field) {
                $messages[$field->Name] = $field->getErrorMessage()->HTML();
                $formField              = $field->getFormField();

                if ($field->Required && $field->CustomRules()->Count() == 0) {
                    if (isset($data[$field->Name])) {
                        $formField->setValue($data[$field->Name]);
                    }

                    if (
                        !isset($data[$field->Name]) ||
                        !$data[$field->Name] ||
                        !$formField->validate($form->getValidator())
                    ) {
                        $form->addErrorMessage($field->Name, $field->getErrorMessage(), 'bad');
                    }
                }
            }

            if (Session::get("FormInfo.{$form->FormName()}.errors")) {
                Controller::curr()->redirectBack();

                return;
            }

            // if there are no errors, create the payment
            $submittedForm                = Object::create('SubmittedPaymentForm');
            $submittedForm->SubmittedByID = ($id = Member::currentUserID()) ? $id : 0;
            $submittedForm->ParentID      = $this->ID;

            // if saving is not disabled save now to generate the ID
            if (!$this->DisableSaveSubmissions) {
                $submittedForm->write();
            }

            $values      = array();
            $attachments = array();

            $submittedFields = new ArrayList();

            foreach ($this->Fields() as $field) {
                if (!$field->showInReports()) {
                    continue;
                }

                $submittedField           = $field->getSubmittedFormField();
                $submittedField->ParentID = $submittedForm->ID;
                $submittedField->Name     = $field->Name;
                $submittedField->Title    = $field->getField('Title');

                // save the value from the data
                if ($field->hasMethod('getValueFromData')) {
                    $submittedField->Value = $field->getValueFromData($data);
                } else {
                    if (isset($data[$field->Name])) {
                        $submittedField->Value = $data[$field->Name];
                    }
                }

                if (!empty($data[$field->Name])) {
                    if (in_array("EditableFileField", $field->getClassAncestry())) {
                        if (isset($_FILES[$field->Name])) {
                            $foldername = $field->getFormField()->getFolderName();

                            // create the file from post data
                            $upload             = new Upload();
                            $file               = new File();
                            $file->ShowInSearch = 0;
                            try {
                                $upload->loadIntoFile($_FILES[$field->Name], $file, $foldername);
                            } catch (ValidationException $e) {
                                $validationResult = $e->getResult();
                                $form->addErrorMessage($field->Name, $validationResult->message(), 'bad');
                                Controller::curr()->redirectBack();
                                return;
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

            /** Do the payment **/
            // move this up here for our redirect link
            $referrer = (isset($data['Referrer'])) ? '?referrer=' . urlencode($data['Referrer']) : "";

            // set amount
            $currency = $this->data()->PaymentCurrency;
            $f        = new EditableFormField();

            $paymentfieldname = $this->PaymentAmountField()->Name;
            $amount           = $data[$paymentfieldname];
            $postdata         = $data;

            // request payment
            $payment = Payment::create()->init("SecurePay_DirectPost", $amount, $currency);
            $payment->write();

            $response = PurchaseService::create($payment)
                ->setReturnUrl($this->Link('finished') . $referrer)
                ->setCancelUrl($this->Link('finished') . $referrer)
                ->purchase($postdata);

            // save payment to order
            $submittedForm->PaymentID = $payment->ID;
            $submittedForm->write();

            $emailData = array(
                "Sender" => Member::currentUser(),
                "Fields" => $submittedFields
            );

            $this->extend('updateEmailData', $emailData, $attachments);

            // email users on submit.
            if ($recipients = $this->FilteredEmailRecipients($data, $form)) {
                $email = new UserDefinedForm_SubmittedFormEmail($submittedFields);

                if ($attachments) {
                    foreach ($attachments as $file) {
                        if ($file->ID != 0) {
                            $email->attachFile(
                                $file->Filename,
                                $file->Filename,
                                HTTP::get_mime_type($file->Filename)
                            );
                        }
                    }
                }

                foreach ($recipients as $recipient) {
                    $email->populateTemplate($recipient);
                    $email->populateTemplate($emailData);
                    $email->setFrom($recipient->EmailFrom);
                    $email->setBody($recipient->EmailBody);
                    $email->setTo($recipient->EmailAddress);
                    $email->setSubject($recipient->EmailSubject);

                    if ($recipient->EmailReplyTo) {
                        $email->setReplyTo($recipient->EmailReplyTo);
                    }

                    // check to see if they are a dynamic reply to. eg based on a email field a user selected
                    if ($recipient->SendEmailFromField()) {
                        $submittedFormField = $submittedFields->find('Name', $recipient->SendEmailFromField()->Name);

                        if ($submittedFormField && is_string($submittedFormField->Value)) {
                            $email->setReplyTo($submittedFormField->Value);
                        }
                    }
                    // check to see if they are a dynamic reciever eg based on a dropdown field a user selected
                    if ($recipient->SendEmailToField()) {
                        $submittedFormField = $submittedFields->find('Name', $recipient->SendEmailToField()->Name);

                        if ($submittedFormField && is_string($submittedFormField->Value)) {
                            $email->setTo($submittedFormField->Value);
                        }
                    }

                    // check to see if there is a dynamic subject
                    if ($recipient->SendEmailSubjectField()) {
                        $submittedFormField = $submittedFields->find('Name', $recipient->SendEmailSubjectField()->Name);

                        if ($submittedFormField && trim($submittedFormField->Value)) {
                            $email->setSubject($submittedFormField->Value);
                        }
                    }

                    $this->extend('updateEmail', $email, $recipient, $emailData);

                    if ($recipient->SendPlain) {
                        $body = strip_tags($recipient->EmailBody) . "\n";
                        if (isset($emailData['Fields']) && !$recipient->HideFormData) {
                            foreach ($emailData['Fields'] as $Field) {
                                $body .= $Field->Title . ' - ' . $Field->Value . " \n";
                            }
                        }

                        $email->setBody($body);
                        $email->sendPlain();
                    } else {
                        $email->send();
                    }
                }
            }

            $submittedForm->extend('updateAfterProcess');

            Session::clear("FormInfo.{$form->FormName()}.errors");
            Session::clear("FormInfo.{$form->FormName()}.data");


            // set a session variable from the security ID to stop people accessing the finished method directly
            if (isset($data['SecurityID'])) {
                Session::set('FormProcessed', $data['SecurityID']);
            } else {
                // if the form has had tokens disabled we still need to set FormProcessed
                // to allow us to get through the finshed method
                if (!$this->Form()->getSecurityToken()->isEnabled()) {
                    $randNum  = rand(1, 1000);
                    $randHash = md5($randNum);
                    Session::set('FormProcessed', $randHash);
                    Session::set('FormProcessedNum', $randNum);
                }
            }

            if (!$this->DisableSaveSubmissions) {
                Session::set('userformssubmission' . $this->ID, $submittedForm->ID);
            }

            return $response->redirect();
        }

        /**
         *
         * @return mixed
         */
        public function finished()
        {
            $submission = Session::get('userformssubmission' . $this->ID);
            $amountnice = '$0';

            if ($submission) {
                $submission = SubmittedPaymentForm::get()->byId($submission);
                if ($payment = $submission->Payment()) {
                    $amountnice = '$' . substr($payment->getAmount(), 0, -2);
                }
            }

            $referrer = isset($_GET['referrer']) ? urldecode($_GET['referrer']) : null;

            $formProcessed = Session::get('FormProcessed');
            if (!isset($formProcessed)) {
                // @todo: work on the "on error" logic - make use of OnErrorMessage
                return $this->redirect($this->Link() . $referrer);
            } else {
                $securityID = Session::get('SecurityID');
                // make sure the session matches the SecurityID and is not left over from another form
                if ($formProcessed != $securityID) {
                    // they may have disabled tokens on the form
                    $securityID = md5(Session::get('FormProcessedNum'));
                    if ($formProcessed != $securityID) {
                        return $this->redirect($this->Link() . $referrer);
                    }
                }
            }
            // remove the session variable as we do not want it to be re-used
            Session::clear('FormProcessed');
            $successmessage = str_replace("[amount]", $amountnice, $this->data()->OnCompleteMessage);
            return $this->customise(array(
                'Content' => $this->customise(array(
                    'Submission'       => $submission,
                    'Link'             => $referrer,
                    'OnSuccessMessage' => $successmessage,
                    'AmountNice'       => $amountnice
                ))->renderWith('ReceivedPaymentFormSubmission'),
                'Form'    => ''
            ));
        }


    }