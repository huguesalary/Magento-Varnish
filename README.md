Magento-Varnish
---------------

This Magento extension provides a *Real* **Full Page Caching** (FPC) for **Magento** powered by **Varnish** with support of **Session-Based** information caching (Cart, Customer Accounts, ...) via **ESI** includes

Table Of Content
----------------

1. [Synopsis](#synopsis)
2. [Requirements](#requirements)
3. [Step by Step installation](#step-by-step-installation)
4. [Caching Policies - How to define what's cached and what's not?](#caching-policies---how-to-define-whats-cached-and-whats-not)
	
	4a. [Where do you define a caching policy?](#where-do-you-define-a-caching-policy)

	4b. [How to define a caching policy on a block](#how-to-define-a-caching-policy-on-a-block)
	
	4c. [List of the different parameters defining a caching policy](#list-of-the-different-parameters-defining-a-caching-policy)
	
	4d. [How to "flush" the cache](#how-to-flush-the-cache)
		
	
	   4d-1. [Flushing Varnish via Magento](#flushing-varnish-via-magento)
		
	   4d-2. [Flushing Varnish cache via command line](#flushing-varnish-cache-via-command-line)
	   
5. [How does the extension work basically?](#how-does-the-extension-work-basically)
6. [Important notes](#important-notes)
7. [Why redis?](#why-redis)
8. [Known bugs](#known-bugs)
9. [TODO](#todo)
10. [Lectures](#lectures)
11. [Apologies](#apologies)
12. [Contact](#contact)
13. [License](#license)

Synopsis
--------

Tired of fake Full Page Cache Magento extensions? Tired of these extensions promising to make your website fly, shooting to the stars, but barely reaching sea level?

This extension is for you.

**Magento-Varnish**:

- Fully Caches your pages
- Fully Caches pages with session based content (for example, your customer cart) thanks to **ESI includes**
- Allows **different caching rules** for different *blocks* in the same page (you can have a block expiring after 10 minutes, another block expiring after 3 days, another after 15 seconds, and so on) thanks to **ESI Includes**
- Automatically refreshes session based pages when needed (for example, your customer adding an item to its cart)
- Refreshes product page cache on product save
- Refreshes category page cache on category save
- Refreshes product page when a product goes out of stock
- Refreshes CMS page after save
- Makes your website fly

Requirements
------------

- [Magento 1.6.1.0](http://www.magentocommerce.com/) (not tested with lower versions or above)
- [Varnish >=3.0.2](https://www.varnish-cache.org/) 
- [Redis](http://redis.io/)
- [Cm\_Cache\_Backend\_Redis](https://github.com/colinmollenhour/Cm_Cache_Backend_Redis) (Included in the extension)
- [php-redis](https://github.com/nicolasff/phpredis)
- [credis](https://github.com/colinmollenhour/credis) (Included in the extension)
- A bit of patience

Step by Step installation
-------------------------

1. [Install Varnish](https://www.varnish-cache.org/docs/3.0/installation/index.html) on your server
2. [Install Redis](http://redis.io/download) on your server
3. [Install phpredis](https://github.com/nicolasff/phpredis#installingconfiguring) on... your server, you got it.
4. Configure your webserver to listen on port **8080**. If you can't use this port, you'll have to make some changes in our configuration. We'll get to that later.
5. Clone this repository and copy and paste the app/ and lib/ directory into your Magento installation
	
	**note**: this extension overrides `Varien_Cache_Core` and `Credis_Client` by creating a file in `app/code/local/Varien/Cache/Core.php` and `app/code/local/Credis/Client.php`. Read more about these *Core Overrides* in the *Important Notes* section at the end of this document.

6. Configure Varnish to use the configuration file provided with the extension. You will find this file in your Magento directory in `app/code/local/Betabrand/Varnish/vcl/magento.vcl`

	6b. If your webserver is **not** configured to listen on port 8080, modify the magento.vcl, line 76. Replace `.port = "8080"` by your port.

You're halfway done!

Connect with your browser to your webserver and see if your Magento installation works. 
If your page doesn't load, you get an error message, or any abnormal behavior, verify that everything is configured correctly. 
You just installed a bunch of software, chances are that you'll have a problem somewhere.

If everything went well, you should see your Homepage. 

Let's keep going:

8. Connect to your Magento administration area (most likely to be `http://yourhost/admin`)
9. Go in System->Cache Management and **disable** every cache
10. Go in System->Configuration->General->Varnish->Module Activation and set "Enable Varnish Module" to *yes* and click save
	
	**note**: Once you set this to *yes* and save, you will certainly notice that "Enable Varnish Module" stayed set to *no*. That's normal! Don't spend hours trying to set it to yes, just go on to the next step.
11. Go back in System->Cache Management and click the "Flush Cache Storage" button.

Caching Policies - How to define what's cached and what's not?
--------------------------------------------------------------

A *caching policy* defines what is stored in cache, how it is cached and how for long.

A *caching policy* is defined on a *block* (`<block></block>`) or a *reference* (`<reference></reference>`). However, you can **only set** a policy on a *block* that extends from **Mage_Core_Block_Template**. On a given page, **different** *caching policies* can be applied to different *blocks*.

By default, the extension caches **everything**. This means that each time a client visits a URL, the content of the page is put in cache and **every subsequent HTTP request to the same url** will be served the cached content.

### Where do you define a caching policy?
Your caching policies are defined via the Magento layout files, just like your `<block></block>`.

### How to define a caching policy on a block
It's easy!

First, remember, by default, the extension caches **everything**. 

If you had some session based information showing on the page (the number of items in your cart for example), this information is cached and every other client accessing the same URL will see the exact page you saw, telling them they have x items in their cart, even if they don't have any!
You will notice however, that the cart information is not cached! How is that possible? Well, that's because the extension **by default** defines a specific caching policy for the cart block stating that the "cart information" should be cached, but, on a *per-client* basis. 
You can find this policy in the *frontend* varnish.xml layout file. 

Ok, so how do you define a caching policy?

Let's use this predefined policy as an example.
 
Open the frontend varnish.xml layout file. Line 12 you can read:

        <reference name="cart_sidebar">
			<action method="setEsi">
            	<params>
            		<cache_type>per-client</cache_type>
            	</params>
            </action>
		</reference>

The `<action method="setEsi">` instructs Magento that we want a specific caching policy on the `cart_sidebar` *block*. The `cart_sidebar` *block* is defined in the base layout file `checkout.xml`.
The caching policy for this block is defined in the `<params></params>` node. The `<params></params>` node contains all the parameters defining our caching policy.

In this case, we only have one parameter called `<cache_type></cache_type>`. This parameters indicates to Magento which type of caching we want on the block. In this case, the type of caching is `per-client`. `per-client` means that we want Varnish to cache a **different** version of the `<block></block>` for **each** client. 

### List of the different parameters defining a caching policy

Given the example above

	<block name="my_block" type="mycompany/myblock">
		<action method="setEsi">
	    	<params>
	    		<cache_type>per-client | per-page | global</cache_type>
	    		<expiry>1w 7d 168h 10080m 604800s</expiry>
	    		<registry_keys>current_product, current_category, my_custom_registry_key1, my_custom_registry_keyN</registry_keys>
	    	</params>
	    </action>
	</block>

***

`<cache_type />`: Can take **one** and **only one** of the following values

		per-client	: one version per client is stored in cache (the session cookie identifies which client gets which cached version of the block)
		per-page	: one version of a block is stored per URL of a page (the url of the page determines which cached version of the block is served to the client)
		global		: one version of a block is stored for the entire website (only one cached version of the block exist and it is served to every client)

***

`<expiry />`: Is a Duration like

		1w (1 week)
		3d (3 days)
		3600s (3600 seconds)

If no expiry is set, by default it will be set to `3d` (3 days), or `10m` (10 minutes) if the `cache_type` is *per-client*. (We set the *per-client* cache expiry to 10 minutes because it would not make sense to keep this data for 3 days).

***

`<registry_keys />`: Registry keys is a very special parameter, that I consider more like a Hack than a feature. It allows you to specify a set (1 or more) of registry keys (you know, Mage::registry() and Mage::register()) that your `<block></block>` is using. You can set 1 or multiple values. In case of multiple values, just separate them with a comma.

		current_product
		current_product, current_category

### How to "flush" the cache

#### Flushing Varnish via Magento
You can flush the "Varnish" cache by going in your Magento Admin in `System->Cache Management`.

By selecting "Varnish" in the "Cache Storage Management" and hitting "Refresh" you will blow away the entire cache. You rarely need to do that.

If you need to refresh a product page, or a category, or a CMS page, just "Edit" the product, category or CMS and "Save". The action of "Saving" automatically triggers a cache refresh for this specific page.

When a product goes out of stock, its page is automatically refreshed.

#### Flushing Varnish cache via command line
You can also flush your cache via the Varnish command line [varnishadm](https://www.varnish-cache.org/docs/3.0/reference/varnishadm.html)

How does the extension work basically?
--------------------------------------

This extension works by adding an Event listener to `core_block_abstract_to_html_before`. This event is dispatched by Magento just before rendering the HTML for a block.

When the extension intercepts the event, it checks if a *caching policy* has been defined on the *block* that is being rendered. If yes, the extension replaces the actual *template* file associated to the *block* with its own template `varnish/esi.phtml`. 

This template renders only one HTML line `<!--esi <esi:include src="<?php echo $this->getSrc()->getUrl() ?>" /> -->`. 

This *ESI include* is then detected by Varnish which will make an HTTP request to the URL defined in the `src` attribute of the `esi:include`.

The request will always go to a URL of the form 'varnish/cacheController/getBlock/cacheType/xxx/expiry/xxx/fingerprint/xxx'.

The `CacheController` receives the request and returns the actual HTML of the *block*.

On the Varnish side, I wrote a specific configuration meant to work with this Magento extension.

Important notes
---------------

 - I wrote this extension on Magento 1.6.1.0, with only **one** store, and **did not** test it on any other version, nor on a **multistore** system.
 - I tried to minimize as **much** as I could *Core Overrides*. 

 Core overrides are a huge pain for developpers and should be avoided: they cause conflicts, make the code hard to maintain and debug. 

 However, because some parts of the Core are *very* old and have been written by what I suspect being "Unpaid Interns", I **had to** override `Mage_Core_Block_Message` and `Varien_Cache_Core`. 

 I also overrided the `Credis_Client` because of a bug. When the `close()` function is called by the destructor, sometime an exception is thrown causing a message to show up `Fatal error: Exception thrown without a stack frame in Unknown on line 0`. In order to avoid this to happen, I added an ugly try-catch somewhere `line 290`.

 Core overrides are most of the time completely avoidable and I still don't understand why so many extensions use them when a simple Event Listener would do the trick, but that's another topic.

 - This extension comes with some default policies. You will certainly don't need to change them. You can find them in the varnish.xml layout file**s** (1 varnish.xml for the frontend area, and one varnish.xml for the admin area).

 - At the moment, this extension **does not** allow caching of any part of the Magento admin area. Trying to define a caching policy for a `<block></block>` defined in the admin layout will failed and throw an exception.

 - Finally, this extension is still in development and by no mean can it be considered stable. However, it is stable enough to be released and any help to make it better is welcome! Feel free to send pull requests, bug fixes and feature requests!

Why Redis?
----------

Redis is used to get around the fact that there's no context persistency between request in PHP. Thus, redis is used to store datas needed by some ESI Includes.

For example, when a caching policy specifies some `<registry_keys />` to keep, the content of these registry keys is stored in redis and retrieved by the ESI request.

Why not storing these in a database table?

My first iteration was using a database table. I decided that this solution was not optimal. However, a feature to develop would be the ability for one to choose his storage via adapters for Memached, MySQL, Redis, File, etc, just like the Magento cache system.

Why not using the Magento Cache System, you idiot?

Well, good question. First, this extension wasn't supposed to be open-sourced when I begun developping it, so nobody was supposed to see the mess. Then, I needed a system that would allow me to list all the ESI includes by cache\_type, block\_type and block\_name. As I said earlier, I first used a database table, but I found that solution to be too complicated and not optimal. I started looking at using the Magento Cache and use "tags" to be able to filter the data. However, I was using Memcached and it doesn't support tags, I could have used the filesystem, but I didn't want because of how slow it would have been. I decided to use redis as it solved all my problems: no database, no table to create and no SQL, no slow access, easy access of my data thanks to the tags.

Now, when I look at it, I see multiple problems with the current implementation of the code:

- First, I made the choice to store **everything about the cached block** in redis. Meaning that the loss of the information stored in redis is equivalent to the loss of an ESI Include.

	As a matter of fact, when a caching policy is defined on a block, the code generate a unique URL for this block.

	This URL is made unique via a "fingerprint" calculated by the `injectEsi()` function. 

	All the data related to this block (name of the block in the layout, store\_id, design\_theme, design\_package) is then saved in cache with the fingerprint as key.

	This fingerprint is then read by the "CacheController" and the data defining the block is pulled out of the cache. 

	Now, if the redis cache is emptied, or the key (finrgerprint) evicted, next time the ESI request is made, the code will return nothing because it won't be able to retrieve the data from the cache. 

	My first implementation was relying on parsing the URL. All the data needed to generate the HTML for an ESI request was available directly in the URL, and redis was used only to store `registry_keys`. 

	I moved away from this because the URL were becoming very long and the code hard to read, but I didn't think about the consequences of the lost of data in redis.
	
	I may move back to my previous iteration.

- Secondly, it should be left to the final user to decide how to store the data. Restricting to redis is not optimal.

Known bugs
----------

 - In the Customer Accounts, when you click on "My Orders" the caching policy applied to the "sales.order.history" block generates an exception: 
 `Fatal error: Call to a member function setHeaderTitle() on a non-object in /app/code/core/Mage/Sales/Block/Order/History.php on line 52`

 If you want to fix this bug, either you have to remove the caching policy, or, you need to override Mage_Sales_Block_Order_History and comment out line 52.
 It's ugly, but not my fault!

 - The same type of bug occurs on the Account Dashboard when you uncomment the caching policy applied on "customer_account_dashboard". In order not to scare people away from the extension by facing almost immediately an exception, I commented out the caching policy.

 If you want to uncomment the caching policy, you'll have to override Mage_Customer_Block_Form_Register and comment out line 43.
 Again, it's ugly, but not my fault!

TODO
----

- Remove some other dirty lines of code
- Translate and use $this->__("")
- Finish coding/debugging the Cache Management area allowing to flush "per-page" cached block. With the current implementation when there's a lot of "per-page" block, the page takes for ever to load.
- Once the previous item is done, allow to flush "global" blocks as well
- Allow more system configuration in general (like enable/disable cache refresh on save, or on out of stock)
- Correct every typos in comments and doc
- Complete the doc with some schema of the interaction between Varnish and Magento
- Create an API to access this extension from other modules and Document this API

Lectures
--------

- About [Edge Server Includes (ESI)](https://www.varnish-cache.org/docs/3.0/tutorial/esi.html?highlight=esi)
- About [Varnish](https://www.varnish-cache.org/docs/3.0/tutorial/introduction.html)
- The [Varnish Book](https://www.varnish-software.com/static/book/)

Apologies
---------

I apologize to Shakespeare for any typos, grammatical errors or bad syntax you may have encountered in this document.

Contact
-------

Don't hesitate to contact me by email at hugues.alary@gmail.com and send me your suggestions or whatever goes through your mind

LICENSE
-------

    This program is free software: you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation, either version 3 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program.  If not, see <http://www.gnu.org/licenses/>.

	Author: Hugues Alary <hugues.alary@gmail.com>


