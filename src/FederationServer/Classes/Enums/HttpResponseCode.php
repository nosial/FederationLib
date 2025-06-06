<?php

    namespace FederationServer\Classes\Enums;

    enum HttpResponseCode : int
    {
        /**
         * This interim response indicates that the client should continue the request or ignore the response if
         * the request is already finished.
         */
        case CONTINUE = 100;

        /**
         * This code is sent in response to an Upgrade request header from the client and indicates the protocol the server is switching to.
         */
        case SWITCHING_PROTOCOLS = 101;

        /**
         * (Deprecated) Used in WebDAV contexts to indicate that a request has been received by the server, but no status was available at the time of the response.
         */
        case PROCESSING = 102;

        /**
         * This status code is primarily intended to be used with the Link header, letting the user agent start preloading resources while the server prepares a response or preconnect to an origin from which the page will need resources.
         */
        case EARLY_HINTS = 103;

        // --- Successful responses ---

        /**
         * The request succeeded. The result and meaning of "success" depends on the HTTP method.
         */
        case OK = 200;

        /**
         * The request succeeded, and a new resource was created as a result. Typically sent after POST or some PUT requests.
         */
        case CREATED = 201;

        /**
         * The request has been received but not yet acted upon. Intended for cases where another process or server handles the request, or for batch processing.
         */
        case ACCEPTED = 202;

        /**
         * The returned metadata is not exactly the same as is available from the origin server, but is collected from a local or a third-party copy.
         */
        case NON_AUTHORITATIVE_INFORMATION = 203;

        /**
         * There is no content to send for this request, but the headers are useful. The user agent may update its cached headers for this resource with the new ones.
         */
        case NO_CONTENT = 204;

        /**
         * Tells the user agent to reset the document which sent this request.
         */
        case RESET_CONTENT = 205;

        /**
         * Used in response to a range request when the client has requested a part or parts of a resource.
         */
        case PARTIAL_CONTENT = 206;

        /**
         * (WebDAV) Conveys information about multiple resources, for situations where multiple status codes might be appropriate.
         */
        case MULTI_STATUS = 207;

        /**
         * (WebDAV) Used inside a <dav:propstat> response element to avoid repeatedly enumerating the internal members of multiple bindings to the same collection.
         */
        case ALREADY_REPORTED = 208;

        /**
         * (HTTP Delta encoding) The server has fulfilled a GET request for the resource, and the response is a representation of the result of one or more instance-manipulations applied to the current instance.
         */
        case IM_USED = 226;

        // --- Redirection messages ---

        /**
         * The request has more than one possible response and the user agent or user should choose one of them.
         */
        case MULTIPLE_CHOICES = 300;

        /**
         * The URL of the requested resource has been changed permanently. The new URL is given in the response.
         */
        case MOVED_PERMANENTLY = 301;

        /**
         * The URI of requested resource has been changed temporarily. Further changes in the URI might be made in the future.
         */
        case FOUND = 302;

        /**
         * The server sent this response to direct the client to get the requested resource at another URI with a GET request.
         */
        case SEE_OTHER = 303;

        /**
         * Used for caching purposes. It tells the client that the response has not been modified.
         */
        case NOT_MODIFIED = 304;

        /**
         * (Deprecated) Defined in a previous version of the HTTP specification to indicate that a requested response must be accessed by a proxy.
         */
        case USE_PROXY = 305;

        /**
         * This response code is no longer used; but is reserved.
         */
        case UNUSED = 306;

        /**
         * The server sends this response to direct the client to get the requested resource at another URI with the same method that was used in the prior request.
         */
        case TEMPORARY_REDIRECT = 307;

        /**
         * The resource is now permanently located at another URI, specified by the Location response header.
         */
        case PERMANENT_REDIRECT = 308;

        // --- Client error responses ---

        /**
         * The server cannot or will not process the request due to something that is perceived to be a client error.
         */
        case BAD_REQUEST = 400;

        /**
         * The client must authenticate itself to get the requested response.
         */
        case UNAUTHORIZED = 401;

        /**
         * The initial purpose of this code was for digital payment systems, however this status code is rarely used and no standard convention exists.
         */
        case PAYMENT_REQUIRED = 402;

        /**
         * The client does not have access rights to the content; that is, it is unauthorized, so the server is refusing to give the requested resource.
         */
        case FORBIDDEN = 403;

        /**
         * The server cannot find the requested resource.
         */
        case NOT_FOUND = 404;

        /**
         * The request method is known by the server but is not supported by the target resource.
         */
        case METHOD_NOT_ALLOWED = 405;

        /**
         * The web server, after performing server-driven content negotiation, doesn't find any content that conforms to the criteria given by the user agent.
         */
        case NOT_ACCEPTABLE = 406;

        /**
         * Similar to 401 Unauthorized but authentication is needed to be done by a proxy.
         */
        case PROXY_AUTHENTICATION_REQUIRED = 407;

        /**
         * This response is sent on an idle connection by some servers, even without any previous request by the client.
         */
        case REQUEST_TIMEOUT = 408;

        /**
         * The request conflicts with the current state of the server.
         */
        case CONFLICT = 409;

        /**
         * The requested content has been permanently deleted from server, with no forwarding address.
         */
        case GONE = 410;

        /**
         * Server rejected the request because the Content-Length header field is not defined and the server requires it.
         */
        case LENGTH_REQUIRED = 411;

        /**
         * In conditional requests, the client has indicated preconditions in its headers which the server does not meet.
         */
        case PRECONDITION_FAILED = 412;

        /**
         * The request body is larger than limits defined by server.
         */
        case CONTENT_TOO_LARGE = 413;

        /**
         * The URI requested by the client is longer than the server is willing to interpret.
         */
        case URI_TOO_LONG = 414;

        /**
         * The media format of the requested data is not supported by the server.
         */
        case UNSUPPORTED_MEDIA_TYPE = 415;

        /**
         * The ranges specified by the Range header field in the request cannot be fulfilled.
         */
        case RANGE_NOT_SATISFIABLE = 416;

        /**
         * The expectation indicated by the Expect request header field cannot be met by the server.
         */
        case EXPECTATION_FAILED = 417;

        /**
         * The server refuses the attempt to brew coffee with a teapot.
         */
        case IM_A_TEAPOT = 418;

        /**
         * The request was directed at a server that is not able to produce a response.
         */
        case MISDIRECTED_REQUEST = 421;

        /**
         * (WebDAV) The request was well-formed but was unable to be followed due to semantic errors.
         */
        case UNPROCESSABLE_CONTENT = 422;

        /**
         * (WebDAV) The resource that is being accessed is locked.
         */
        case LOCKED = 423;

        /**
         * (WebDAV) The request failed due to failure of a previous request.
         */
        case FAILED_DEPENDENCY = 424;

        /**
         * Indicates that the server is unwilling to risk processing a request that might be replayed.
         */
        case TOO_EARLY = 425;

        /**
         * The server refuses to perform the request using the current protocol but might be willing to do so after the client upgrades to a different protocol.
         */
        case UPGRADE_REQUIRED = 426;

        /**
         * The origin server requires the request to be conditional.
         */
        case PRECONDITION_REQUIRED = 428;

        /**
         * The user has sent too many requests in a given amount of time (rate limiting).
         */
        case TOO_MANY_REQUESTS = 429;

        /**
         * The server is unwilling to process the request because its header fields are too large.
         */
        case REQUEST_HEADER_FIELDS_TOO_LARGE = 431;

        /**
         * The user agent requested a resource that cannot legally be provided, such as a web page censored by a government.
         */
        case UNAVAILABLE_FOR_LEGAL_REASONS = 451;

        // --- Server error responses ---

        /**
         * The server has encountered a situation it does not know how to handle.
         */
        case INTERNAL_SERVER_ERROR = 500;

        /**
         * The request method is not supported by the server and cannot be handled.
         */
        case NOT_IMPLEMENTED = 501;

        /**
         * The server, while working as a gateway to get a response needed to handle the request, got an invalid response.
         */
        case BAD_GATEWAY = 502;

        /**
         * The server is not ready to handle the request. Common causes are a server that is down for maintenance or that is overloaded.
         */
        case SERVICE_UNAVAILABLE = 503;

        /**
         * The server is acting as a gateway and cannot get a response in time.
         */
        case GATEWAY_TIMEOUT = 504;

        /**
         * The HTTP version used in the request is not supported by the server.
         */
        case HTTP_VERSION_NOT_SUPPORTED = 505;

        /**
         * The server has an internal configuration error: during content negotiation, the chosen variant is configured to engage in content negotiation itself.
         */
        case VARIANT_ALSO_NEGOTIATES = 506;

        /**
         * (WebDAV) The method could not be performed on the resource because the server is unable to store the representation needed to successfully complete the request.
         */
        case INSUFFICIENT_STORAGE = 507;

        /**
         * (WebDAV) The server detected an infinite loop while processing the request.
         */
        case LOOP_DETECTED = 508;

        /**
         * The client request declares an HTTP Extension (RFC 2774) that should be used to process the request, but the extension is not supported.
         */
        case NOT_EXTENDED = 510;

        /**
         * Indicates that the client needs to authenticate to gain network access.
         */
        case NETWORK_AUTHENTICATION_REQUIRED = 511;

        /**
         * Converts the enum case to a string suitable for use as an error prefix.
         * For example, if the enum case is `NOT_FOUND`, it will return "Not Found".
         *
         * @return string The name of the enum case formatted as a human-readable string.
         */
        public function getErrorPrefix(): string
        {
            return ucwords(strtolower(str_replace('_', ' ', $this->name)));
        }
    }
