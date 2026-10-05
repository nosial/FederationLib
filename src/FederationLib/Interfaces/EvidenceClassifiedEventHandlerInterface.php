<?php

    namespace FederationLib\Interfaces;

    use FederationLib\Enums\ClassificationFlag;
    use FederationLib\Objects\EvidenceRecord;

    interface EvidenceClassifiedEventHandlerInterface
    {
        /**
         * Handles an evidence record that was assigned a classification, either when the evidence was submitted with
         * a classification or when an unclassified evidence record was classified (directly or by closing a report).
         * Classifications are immutable, so this is executed at most once per evidence record.
         *
         * Event handlers are executed synchronously in the process that classified the evidence (which may be a
         * request), they must not send a response. Any exception thrown is logged and does not affect the evidence
         * record or the operation that classified it.
         *
         * @param EvidenceRecord $evidence The evidence record, including its text content (if any)
         * @param ClassificationFlag $classification The classification assigned to the evidence
         * @return void
         */
        public static function handleEvidenceClassified(EvidenceRecord $evidence, ClassificationFlag $classification): void;
    }
