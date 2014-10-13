silverstripe-userforms-payments
===============================

A small mod that adds the ability to accept payments from User Defined Forms.
-----------------------------------------------------------------------------
- Requirements
  * The module is built entirely on UserForms Module <https://github.com/silverstripe/silverstripe-userforms> and SilverStripe Payments via Omnipay <https://github.com/burnbright/silverstripe-omnipay>

- Limitations
  * Currently, the "Amount" variable is visible and therefore easy to manipulate on the front-end. This is intentional, as the form was designed as a quick "Donation Form", where the user could select their own donation amount easily.
  * Omnipay fields are not saved to the database, in order to save them, make some hiddenfields in userforms and do some js magic - this will be fixed in future
    
### Disclaimer:
This is a super crazy early alpha version of the module. It has been tested and is working on some of our sites, but I can't guarantee it will work for you. Any help getting this up to scratch would be greatly appreciated!