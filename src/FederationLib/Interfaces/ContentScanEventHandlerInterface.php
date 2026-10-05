<?php

    namespace FederationLib\Interfaces;

    use FederationLib\Exceptions\ContentScanRejectedException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\Objects\Plugin\ContentScan;

    interface ContentScanEventHandlerInterface
    {
        /**
         * Handles a content scan request, executed during the scan after FederationLib resolved the entities and
         * classified the content and before the scan results are recorded or returned.
         *
         * The handler has access to everything that was provided with the request and everything FederationLib
         * found so far through the ContentScan object, and may optionally:
         *
         *  - Add its own scanning rules to the scan results with ContentScan::addScanResult(), these are included in
         *    the scan results and the risk score of the response (positive points lower the risk, negative points
         *    raise it, the same as FederationLib's own scanning rules)
         *  - Classify the content with ContentScan::addClassification(), the classification is included in the scan
         *    results exactly like FederationLib's own classifications
         *  - Reject the request with ContentScan::reject() (or by throwing a RequestException), the client receives
         *    an error response and nothing about the scan is recorded
         *
         * Any other exception thrown by the handler is logged and the scan continues without the scanning rules and
         * classifications the handler added.
         *
         * @param ContentScan $contentScan The content scan
         * @return void
         * @throws ContentScanRejectedException|RequestException If the content scan request is rejected
         */
        public static function handleContentScan(ContentScan $contentScan): void;
    }
