<?php

    namespace TestPlugin\EventHandlers;

    use FederationLib\Enums\ClassificationFlag;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Interfaces\ContentScanEventHandlerInterface;
    use FederationLib\Objects\Plugin\ContentScan;
    use FederationLib\Objects\ScannedContent\ContentClassification;
    use RuntimeException;
    use TestPlugin\ContentScanEvents;

    /**
     * A CONTENT_SCAN event handler that only acts on content containing one of its trigger words, so that it never
     * affects the scans of the other test units:
     *
     *  - test_plugin_reject: rejects the request (451)
     *  - test_plugin_penalty: adds the TEST_PLUGIN_PENALTY scanning rule (-20 points)
     *  - test_plugin_bonus: adds the TEST_PLUGIN_BONUS scanning rule twice (2 x 2.5 points, accumulated)
     *  - test_plugin_classify: classifies each evidence item containing it as MALICIOUS (0.9 confidence)
     *  - test_plugin_fail: adds the TEST_PLUGIN_FAILED scanning rule and a classification and then throws, both must
     *    be discarded
     *  - test_plugin_inspect: records what the handler received (see ContentScanEvents)
     */
    class ContentScanEventHandler implements ContentScanEventHandlerInterface
    {
        /**
         * @inheritDoc
         */
        public static function handleContentScan(ContentScan $contentScan): void
        {
            $content = implode("\n", $contentScan->getTextContents());

            if(str_contains($content, 'test_plugin_inspect'))
            {
                ContentScanEvents::record($contentScan);
            }

            if(str_contains($content, 'test_plugin_reject'))
            {
                $contentScan->reject('The TestPlugin does not accept this content', HttpResponseCode::UNAVAILABLE_FOR_LEGAL_REASONS);
            }

            if(str_contains($content, 'test_plugin_penalty'))
            {
                $contentScan->addScanResult('TEST_PLUGIN_PENALTY', -20.0);
            }

            if(str_contains($content, 'test_plugin_bonus'))
            {
                $contentScan->addScanResult('TEST_PLUGIN_BONUS', 2.5);
                $contentScan->addScanResult('TEST_PLUGIN_BONUS', 2.5);
            }

            foreach($contentScan->getEvidence() as $evidenceIndex => $evidence)
            {
                if($evidence->getTextContent() !== null && str_contains($evidence->getTextContent(), 'test_plugin_classify'))
                {
                    $contentScan->addClassification(new ContentClassification(ClassificationFlag::MALICIOUS, 0.9, 'en'), $evidenceIndex);
                }
            }

            if(str_contains($content, 'test_plugin_fail'))
            {
                $contentScan->addScanResult('TEST_PLUGIN_FAILED', -50.0);
                $contentScan->addClassification(new ContentClassification(ClassificationFlag::MALICIOUS, 1.0, 'en'));
                throw new RuntimeException('ContentScanEventHandler intentionally failed');
            }
        }
    }
