===========================================================================
 TwittPay - OpenCart 4 extension
===========================================================================

 INSTALL
   1. Zip the contents of this folder so that install.json is at the top of
      the zip (or download twittpay.ocmod.zip from the Releases page).
   2. OpenCart admin > Extensions > Installer > Upload.
   3. Extensions > Extensions > choose "Payments" > TwittPay > Install,
      then Edit.

 SETTINGS
        Brand Key            from your TwittPay dashboard, under Brands
        USD to BDT rate      only used when the order currency is USD
        Order status         one for paid, one for waiting
        Status               Enabled

 NOTES
   * TwittPay takes BDT. BDT orders go through as they are. USD orders are
     converted with the rate above. Other currencies do not show the method.
   * Every payment is verified again on your server before an order is marked
     paid, and an order is marked paid only once.
   * Make one small test payment after installing.
