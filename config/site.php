<?php
/**
 * Optional public contact details shown in the site footer.
 * Leave a value as '' to hide it. Nothing here is required.
 *
 * You can also set them as environment variables on your host:
 *   CSO_CONTACT_EMAIL, CSO_FACEBOOK_URL, CSO_LOCATION
 */
if (!defined('CSO_CONTACT_EMAIL')) define('CSO_CONTACT_EMAIL', getenv('CSO_CONTACT_EMAIL') ?: 'csonorsu@gmail.com');
if (!defined('CSO_FACEBOOK_URL')) define('CSO_FACEBOOK_URL', getenv('CSO_FACEBOOK_URL') ?: 'https://www.facebook.com/profile.php?id=61550668835707');
if (!defined('CSO_LOCATION'))     define('CSO_LOCATION',     getenv('CSO_LOCATION')     ?: '');
