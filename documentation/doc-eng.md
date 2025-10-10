# PaynetEasy Payment Plugin for Joomla 3.x

# 1. [Requirements](https://github.com/annihilatoratm/joomla-doc/blob/main/documentation/doc-eng.md#requirements)
# 2. [Functionality](https://github.com/annihilatoratm/joomla-doc/blob/main/documentation/doc-eng.md#functionality)
# 3. [Package Build](https://github.com/annihilatoratm/joomla-doc/blob/main/documentation/doc-eng.md#package-build)
# 4. [Plugin Installation](https://github.com/annihilatoratm/joomla-doc/blob/main/documentation/doc-eng.md#plugin-installation)
# 5. [Plugin Configuration](https://github.com/annihilatoratm/joomla-doc/blob/main/documentation/doc-eng.md#plugin-configuration)
# 6. [Plugin Uninstallation](https://github.com/annihilatoratm/joomla-doc/blob/main/documentation/doc-eng.md#plugin-uninstallation)
# 7. [User Registration](https://github.com/annihilatoratm/joomla-doc/blob/main/documentation/doc-eng.md#user-registration)
# 8. [Payment Flow](https://github.com/annihilatoratm/joomla-doc/blob/main/documentation/doc-eng.md#payment-flow)

## 1. Requirements
 * PHP version: 5.3 - 5.5.
 * [curl extension](http://php.net/manual/en/book.curl.php).
 * [Joomla](http://www.joomla.org/download.html) 3.x (plugin v3.1).
 * [JoomShopping](http://joomshopping.pro/download/component.html) 4.x (plugin v4.3).
  

## 2. Functionality
- [Sale Transactions](https://doc.payneteasy.eu/integration/api_use_cases/server_to_server_sale.html)
- [Payment Form Integration](https://doc.payneteasy.eu/integration/api_use_cases/sale_form.html)

## 3. Package Build  
3.1. [Install Composer](http://getcomposer.org/doc/00-intro.md) (if not already installed):  
3.2. Clone the Plugin Repository: `composer create-project payneteasy/php-plugin-joomshopping --stability=dev --prefer-dist`  
3.3. Navigate to Plugin Directory: `cd php-plugin-joomshopping`  
3.4. Build the Archive Package: `composer archive --format=zip`  

# 4. Plugin Installation  
4.1. [Download the plugin package](../README.md#get_package).  
4.2. Log in to the Joomla Admin Panel.  
4.3. Navigate to:  
 * Componenets (1) -> JoomShopping (2) -> Install & Update (3).

4.4. In the opened window:   
 * Click _Choose File_ (4) to select the plugin archive.  
 * Click _Upload_ (5) to complete the installation.  
     
<img src="/images/joomla-1-1-2.png" width=60% height=60%>

## 5. Plugin Configuration  

5.1. Go to:   
 * Componenets (1) -> JoomShopping (2) -> Options (3) -> Payments (4).

5.2. Two plugin integration types are available:    
 * **Payneteasy sale form** - Form-based/off-site integration.  
 * **PaynetEasy sale** - Direct/on-site integration.  

5.3. Click the _Edit icon_ (4) next to the desired plugin to open the configuration settings.    

<img src="/images/joomla-1-1-2.png" width=60% height=60%>
<img src="/images/joomla-1-1-3.png" width=60% height=60%>
<img src="/images/joomla-1-1-4-form.png" width=60% height=60%>
<img src="/images/joomla-1-1-4-direct.png" width=60% height=60%>

## 6. Plugin Uninstallation  
 6.1. In the **Payments** configuration window, select the plugin(s) to remove.  
 6.2. Click the _Delete_ button at the top of the page.  

<img src="/images/joomla-1-1-5.png" width=60% height=60%>
  
 6.3. For complete uninstallation, manually delete the following directories/files from Joomla's root:  

  * `components/vendor`
  * `components/com_jshopping/payments/pm_payneteasy_abstract`
  * `components/com_jshopping/payments/pm_payneteasy_sale`
  * `components/com_jshopping/payments/pm_payneteasy_saleform`

## 7. User Registration  
  
 7.1. Click _Create an Account_ to begin the user registration process. Fill in all required fields and click Register to complete registration.  

<img src="/images/joomla-register-1.png" width=60% height=60%>
<img src="/images/joomla-register-2.png" width=60% height=60%>

 7.2. After successful registration, you will be able to log in to the shop.

<img src="/images/joomla-register-3.png" width=60% height=60%>
<img src="/images/joomla-register-4.png" width=60% height=60%>

## 8. Payment Flow

 8.1. On the main page, click **Shop** to browse product categories.  
  * Select a category, choose a product, and click Buy.  
  * Click Checkout to proceed.  

<img src="/images/joomla-1.png" width=60% height=60%>
<img src="/images/joomla-2.png" width=60% height=60%>
<img src="/images/joomla-3.png" width=60% height=60%>
<img src="/images/joomla-4.png" width=60% height=60%>
  
 8.2. Complete all required information on the **Address** and **Delivery Method** pages.  

<img src="/images/joomla-5.png" width=60% height=60%>
<img src="/images/joomla-6.png" width=60% height=60%>

 8.3. On the **Payment Method** page, select blabla sale as your payment option. Fill in all mandatory fields.  

<img src="/images/joomla-7.png" width=60% height=60%>

 8.4. Review and accept the **Terms of Service and Return Policy**, then click Confirm Order to finalize the purchase.  

<img src="/images/joomla-8.png" width=60% height=60%>
<img src="/images/joomla-9.png" width=60% height=60%>
<img src="/images/joomla-10.png" width=60% height=60%>
