<?php

    class UserDefinedPaymentFormEmailRecipientExtension extends DataExtension
    {

        private static $db = array(
            "SendCreated"    => "Boolean",
            "SendAuthorized" => "Boolean",
            "SendCaptured"   => "Boolean",
            "SendRefunded"   => "Boolean",
            "SendVoid"       => "Boolean"
        );

        public function SendForStatus($status = false)
        {
            $var = "Send" . $status;
            return ($status) ? $this->owner->$var : true;
        }

        public function updateCMSFields(FieldList $fields)
        {
            if ($this->owner->Form() && $this->owner->Form() instanceof UserDefinedPaymentForm) {
                $fields->insertAfter(new CheckboxField('SendCreated', _t('UserDefinedPaymentForm.SendCreated', 'Send email for transactions that are saved as Created')), "SendPlain");
                $fields->insertAfter(new CheckboxField('SendAuthorized', _t('UserDefinedPaymentForm.SendAuthorized', 'Send email for transactions that are saved as Authorized')), "SendCreated");
                $fields->insertAfter(new CheckboxField('SendCaptured', _t('UserDefinedPaymentForm.SendCaptured', 'Send email for transactions that are saved as Captured')), "SendAuthorized");
                $fields->insertAfter(new CheckboxField('SendRefunded', _t('UserDefinedPaymentForm.SendRefunded', 'Send email for transactions that are saved as Refunded')), "SendCaptured");
                $fields->insertAfter(new CheckboxField('SendVoid', _t('UserDefinedPaymentForm.SendVoid', 'Send email for transactions that are saved as Void')), "SendRefunded");
            }
        }

    }