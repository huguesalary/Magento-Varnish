#
# Module Varnish VCL Configuration file
#
# @author     	Hugues Alary <hugues.alary@gmail.com>
# @copyright  	2012
# @license		GNU General Public License, version 3 (GPL-3.0)
#


# Beginning of the config
import std;

backend default {
	.host = "127.0.0.1";
	.port = "8080";
	.connect_timeout = 300s;
	.first_byte_timeout = 300s;
	.between_bytes_timeout = 300s;
}

acl ban {
	/* Access List for the BAN requests */
	"127.0.0.1";
}

acl debugACL {
	/* List of IP that will get the debug headers */
	"127.0.0.1";
}

sub vcl_recv {
	
	# Varnish doesn't like url containing double slashes
	# as such, we change double slashes to simple slashes
	if(req.url ~ "^(.*)//(.*)$")
	{
		set req.url = regsub(req.url,"^(.*)//(.*)$","\1/\2");
	}

	# Strip google analytics params and mailchimp params
	if(req.url ~ "(\?|&)(gclid|utm_[a-z]+|mc_[a-z]+)=") {
    		set req.url = regsuball(req.url, "(gclid|utm_[a-z]+|mc_[a-z]+)=[^\&]+&?", "");
    		set req.url = regsub(req.url, "(\?|&)$", "");
  	}

	# HTTP BAN implementation
	/* Read a bit of documentation: https://www.varnish-cache.org/docs/trunk/tutorial/purging.html */
	if(req.request == "BAN")
	{	
		# If the client is not in the acl of allowed purger
		if (!client.ip ~ ban) {
			error 405 "Not allowed.";
		}
		
		if(req.http.X-Magento-Regexp)
		{
			/* When the backend sends a BAN request, it must send a X-Magento-Regexp containing a PRCE regular expression matching the url(s) to ban */
			/* We use obj.X-Varnish-Url to be ban-luker friendly, please read doc at the link above */
			set req.http.X-Varnish-BanExpr = "obj.http.X-Varnish-Url ~ "+req.http.X-Magento-Regexp;
		} else {
			/* If no Regexp has been set by the backend, we ban the url of the request */
			set req.http.X-Varnish-BanExpr = "obj.http.X-Varnish-Url == "+req.url;
		}
		
		if(req.http.Cookie ~ "frontend")
		{
			/* If a frontend cookie has been specified with the BAN request, we only ban objects matching this specific session cookie */
			/* Objects belonging to a specific session have obj.http.X-Varnish-Session set to a session id */
			/* See vcl_fetch */
			set req.http.X-Varnish-BanExpr =  req.http.X-Varnish-BanExpr + " && obj.http.X-Varnish-Session == " + regsub(req.http.Cookie,"^.*?frontend=([^;]*);*.*$","\1");
		}
		
		# And most importanly, we BAN *ONLY FOR* the HTTP Host to which the request was made
		set req.http.X-Varnish-BanExpr = req.http.X-Varnish-BanExpr + " && obj.http.host == "+req.http.host;

		/* Finally we enter our ban expression in Varnish ban list */
		ban(req.http.X-Varnish-BanExpr);
		
		error 200 "BAN Added "+ req.http.X-Varnish-BanExpr;
	}

    if(req.request == "PROBE")
    {
        # If the client is not in the acl of allowed purger
        if (!client.ip ~ ban) {
            error 405 "Not allowed.";
        }

        return (lookup);
    }

	if (req.request != "GET" &&
		req.request != "HEAD" &&
		req.request != "PUT" &&
		req.request != "POST" &&
		req.request != "TRACE" &&
		req.request != "OPTIONS" &&
		req.request != "DELETE") {
		/* Non-RFC2616 or CONNECT which is weird. */
		return (pipe);
	}

   	if (req.request != "GET" && req.request != "HEAD") {
       	/* We only deal with GET and HEAD by default */
       	return (pass);
   	}

	# We forbid the access to the /varnish/cache/getBlock url if it has not been made internally (req.esi_level == 0)
	# And we redirect to the cart
	# The redirection to the cart is a hack because of a weird bug happening only on production:
	#  if you remove a product from your cart, instead of being redirected to /checkout/cart you get redirected to the /varnish/cache/getBlock url.
	if(req.url ~ "^/varnish/cache/getBlock/.*$" && req.esi_level < 1)
	{
		set req.http.Location = "http://" + req.http.host + "/checkout/cart";
		error 750 "Permanently moved";
	}

	# Encoding normalization.
	if (req.http.Accept-Encoding) {
	    if (req.url ~ "\.(jpg|png|gif|gz|tgz|bz2|tbz|mp3|ogg)$") {
	        # No point in compressing these
	        remove req.http.Accept-Encoding;
	    } elsif (req.http.Accept-Encoding ~ "gzip") {
	        set req.http.Accept-Encoding = "gzip";
	    } elsif (req.http.Accept-Encoding ~ "deflate") {
	        set req.http.Accept-Encoding = "deflate";
	    } else {
	        # unknown algorithm
	        remove req.http.Accept-Encoding;
	    }
	}

	# We remove cookies sent along with request for static files
	if (req.url ~ "^[^?]*\.(css|js|htc|xml|txt|swf|flv|pdf|gif|jpe?g|png|ico)$") { # Some known-static file types
		/* Pretent no cookie was passed */
		unset req.http.Cookie;
	}
	
	if (req.http.Authorization) {
		/* Not cacheable by default */
		return (pass);
	}

	return (lookup);
}

sub vcl_hit {
	if(req.request == "PROBE")
	{
		error 760;
	}

	return (deliver);
}

sub vcl_miss {
	if(req.request == "PROBE")
	{
		error 761;
	}

	if(req.esi_level > 0) {
		/* the request is an <esi:include> */
		unset bereq.http.accept-encoding;
	}

	return (fetch);
}

sub vcl_fetch{

	#If the response from the backend is not a valid one, we don't cache it
	if (beresp.status == 302 || beresp.status == 301 || beresp.status > 400) 
	{
		set beresp.ttl = 120s; # We remember the hit_for_pass decision for 2 minutes
		return (hit_for_pass);
	}
	
	# Every object in the cache needs to have the X-Varnish-Url and the http.host set respectively to the req.url and req.http.host for the BAN-lurker friendly HTTP implementation
	set beresp.http.X-Varnish-Url = req.url;
	set beresp.http.host = req.http.host;
	
	# We ask varnish to parse eventual ESI includes
	set beresp.do_esi = true;
	
	# We GZIP everything
	set beresp.do_gzip = true;

	# If the backend has set this header, we don't cache
	if(beresp.http.X-Magento-DoNotCache == "1")
	{
		set beresp.ttl = 0s;
		return (hit_for_pass);
	}

	# We remove the set-cookie
	remove beresp.http.Set-Cookie;
	remove beresp.http.X-Cache;
	remove beresp.http.Server;
	remove beresp.http.Age;
	remove beresp.http.Pragma;
	remove beresp.http.Expires;
	remove beresp.http.X-Powered-By;
	set beresp.http.Cache-Control = "public";
	set beresp.grace = 2m;

    # We check if there's an expiry set (there should always be one)
    if(req.url ~ "expiry/[0-9]+(d|h|m|s)/")
    {
        set beresp.ttl = std.duration(regsub(req.url,".*expiry/([0-9]+(w|d|h|m|s))/.*","\1"),3d);
    }
    else
    {
        set beresp.ttl = 3d;
    }

	set beresp.http.X-Powered-By = "PHP/3.2 Microsoft-IIS/5.0";
	set beresp.http.X-Varnish-Cacheable = "YES: Cacheable";
	set beresp.http.X-Varnish-Fpc = "Removed cookie in vcl_fetch";
	
	# For every static files we set a Max-Age to 1 Week in the future
	if(req.url ~ "^[^?]*\.(css|js|htc|xml|txt|swf|flv|pdf|gif|jpe?g|png|ico)$")
	{
		if(req.url ~ "/media/catalog/")
		{
			/* 1 Year for catalog images */
			set beresp.http.Cache-Control = "public, max-age=33868800";
		}
		else 
		{
			/* 1 Week for the rest */
			set beresp.http.Cache-Control = "public, max-age=604800";
		}
		set beresp.http.magicmarker = "1";
	}

	# If we are fetching a request containing cachetype/client
	# We cache the request per a per-client basis
	if(req.url ~ "cachetype/per-client"){
		/* If there's a "frontend" cookie set with the esi request we save the session ID in the object in cache */
		/* This is usefull for a per-client based ban */
		/* See in vcl_recv the implementation of a "BAN" request */
		if(req.http.Cookie ~ "frontend")
		{
			set beresp.http.X-Varnish-Session = regsub(req.http.Cookie,"^.*?frontend=([^;]*);*.*$","\1");
		}
	}

	return (deliver);
}

sub vcl_hash {
	
	hash_data(req.url);
	
	if (req.http.host) {
		hash_data(req.http.host);
	} else {
		hash_data(server.ip);
	}
	
	if(req.url ~ "cachetype/per-client")
	{
		/* If the request is contains /cachetype/per-client */
		/* We add to the hash of the object the value of frontend cookie */
		/* This allows a Per Client Cache */
		/* With a LOT of client this could lead to a fast fill up of the cache. Per Client Cache should have a low TTL. */
		if(req.http.Cookie ~ "frontend")
		{
			hash_data(regsub(req.http.Cookie,"^.*?frontend=([^;]*);*.*$","\1"));
		}
	}
	
	return (hash);
}

/*
Adding debugging information
*/
sub vcl_deliver {
	
	if(client.ip ~ debugACL) {
		if (obj.hits > 0) {
			set resp.http.X-Varnish-Cache = "HIT " + obj.hits;
		} else {
			set resp.http.X-Varnish-Cache = "MISS";
		}
		
		if(req.http.Cookie !~ "frontend")
		{
			set resp.http.X-Varnish-Frontend-Cookie = "No Frontend Cookie";
		} else {
			set resp.http.X-Varnish-Frontend-Cookie = regsub(req.http.Cookie,"^.*?frontend=([^;]*);*.*$","\1");
		}
		set resp.http.X-Varnish-EsiLevel = req.esi_level;	
		set resp.http.X-Varnish-Storage = {""} + storage.memory.free_space/1024/1024 + " " + storage.memory.used_space/1024/1024 + " " + storage.memory.happy;		
		set resp.http.X-Varnish-Session = req.http.X-Varnish-Session;
	}
	else {
		remove resp.http.X-Varnish;
		remove resp.http.Via;
		remove resp.http.X-Magento-DoEsi;
		remove resp.http.X-Magento-DoNotCache;
		remove resp.http.X-Varnish-Cacheable;
		remove resp.http.X-Varnish-Fpc;
		remove resp.http.X-Varnish-Session;
		remove resp.http.X-Varnish-Url;
	}

	if(resp.http.magicmarker)
	{
		/* Remove the magic marker */
        	unset resp.http.magicmarker;

        	/* By definition we have a fresh object */
        	set resp.http.Age = "0";
	}

}

sub vcl_error
{
	#We forbid the access to the /varnish/cache/getBlock url if it has not been made internally (req.esi_level == 0)
	if(obj.status == 750)
	{
		set obj.http.X-Rrrr = "1";
		set obj.http.location = req.http.Location; #Watch out for the Case location vs Location;
		set obj.status = 301;
		return (deliver);
	}
}

# 
# Below is a commented-out copy of the default VCL logic.  If you
# redefine any of these subroutines, the built-in logic will be
# appended to your code.
# sub vcl_recv {
#     if (req.restarts == 0) {
# 	if (req.http.x-forwarded-for) {
# 	    set req.http.X-Forwarded-For =
# 		req.http.X-Forwarded-For + ", " + client.ip;
# 	} else {
# 	    set req.http.X-Forwarded-For = client.ip;
# 	}
#     }
#     if (req.request != "GET" &&
#       req.request != "HEAD" &&
#       req.request != "PUT" &&
#       req.request != "POST" &&
#       req.request != "TRACE" &&
#       req.request != "OPTIONS" &&
#       req.request != "DELETE") {
#         /* Non-RFC2616 or CONNECT which is weird. */
#         return (pipe);
#     }
#     if (req.request != "GET" && req.request != "HEAD") {
#         /* We only deal with GET and HEAD by default */
#         return (pass);
#     }
#     if (req.http.Authorization || req.http.Cookie) {
#         /* Not cacheable by default */
#         return (pass);
#     }
#     return (lookup);
# }
# 
# sub vcl_pipe {
#     # Note that only the first request to the backend will have
#     # X-Forwarded-For set.  If you use X-Forwarded-For and want to
#     # have it set for all requests, make sure to have:
#     # set bereq.http.connection = "close";
#     # here.  It is not set by default as it might break some broken web
#     # applications, like IIS with NTLM authentication.
#     return (pipe);
# }
# 
# sub vcl_pass {
#     return (pass);
# }
# 
# sub vcl_hash {
#     hash_data(req.url);
#     if (req.http.host) {
#         hash_data(req.http.host);
#     } else {
#         hash_data(server.ip);
#     }
#     return (hash);
# }
# 
# sub vcl_hit {
#     return (deliver);
# }
# 
# sub vcl_miss {
#     return (fetch);
# }
# 
# sub vcl_fetch {
#     if (beresp.ttl <= 0s ||
#         beresp.http.Set-Cookie ||
#         beresp.http.Vary == "*") {
# 		/*
# 		 * Mark as "Hit-For-Pass" for the next 2 minutes
# 		 */
# 		set beresp.ttl = 120 s;
# 		return (hit_for_pass);
#     }
#     return (deliver);
# }
# 
# sub vcl_deliver {
#     return (deliver);
# }
# 
# sub vcl_error {
#     set obj.http.Content-Type = "text/html; charset=utf-8";
#     set obj.http.Retry-After = "5";
#     synthetic {"
# <?xml version="1.0" encoding="utf-8"?>
# <!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN"
#  "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
# <html>
#   <head>
#     <title>"} + obj.status + " " + obj.response + {"</title>
#   </head>
#   <body>
#     <h1>Error "} + obj.status + " " + obj.response + {"</h1>
#     <p>"} + obj.response + {"</p>
#     <h3>Guru Meditation:</h3>
#     <p>XID: "} + req.xid + {"</p>
#     <hr>
#     <p>Varnish cache server</p>
#   </body>
# </html>
# "};
#     return (deliver);
# }
# 
# sub vcl_init {
# 	return (ok);
# }
# 
# sub vcl_fini {
# 	return (ok);
# }