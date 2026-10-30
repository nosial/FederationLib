<?php

    namespace FederationLib\Interfaces;

    use FederationLib\Exceptions\RemoteRetrievalRejectedException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\Objects\Plugin\RemoteRetrievalRequest;

    interface RemoteRetrievalEventHandlerInterface
    {
        /**
         * Handles a URL the server is about to retrieve on behalf of a client, executed after the URL was validated and
         * its host resolved to a permitted address, and before any connection is made. It's executed again for the
         * target of every redirect.
         *
         * @param RemoteRetrievalRequest $request The URL about to be retrieved
         * @return void
         * @throws RemoteRetrievalRejectedException|RequestException If the request is rejected
         */
        public static function handleRemoteRetrieval(RemoteRetrievalRequest $request): void;
    }
