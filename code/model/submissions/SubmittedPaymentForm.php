<?php

    /**
     * Contents of an UserDefinedForm submission with a relational link to the payment object
     *
     * @package userforms-payments
     */
    class SubmittedPaymentForm extends SubmittedForm
    {
        private static $has_one = array(
            "Payment" => "Payment"
        );
    }
