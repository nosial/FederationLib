<?php

    namespace FederationLib\Objects;

    use FederationLib\Enums\RetrievalPurpose;

    class RemoteRetrievalContext
    {
        private RetrievalPurpose $purpose;
        private ?OperatorRecord $operator;
        private ?string $entityUuid;
        private ?string $evidenceUuid;
        private ?int $evidenceIndex;
        private ?int $fileIndex;

        /**
         * RemoteRetrievalContext constructor.
         *
         * @param RetrievalPurpose $purpose Why the URL is retrieved
         * @param OperatorRecord|null $operator The operator that supplied the URL, null if anonymous
         * @param string|null $entityUuid The entity the retrieved file belongs to, if known: the entity of the evidence
         *                                of a URL upload, the resolved author of a content scan
         * @param string|null $evidenceUuid The evidence record the file is attached to, for a URL upload
         * @param int|null $evidenceIndex The index of the evidence item that names the URL, for a content scan
         * @param int|null $fileIndex The file index the retrieved file receives, for a content scan
         */
        public function __construct(RetrievalPurpose $purpose, ?OperatorRecord $operator=null, ?string $entityUuid=null, ?string $evidenceUuid=null,
                                    ?int $evidenceIndex=null, ?int $fileIndex=null)
        {
            $this->purpose = $purpose;
            $this->operator = $operator;
            $this->entityUuid = $entityUuid;
            $this->evidenceUuid = $evidenceUuid;
            $this->evidenceIndex = $evidenceIndex;
            $this->fileIndex = $fileIndex;
        }

        /**
         * Returns why the URL is retrieved
         *
         * @return RetrievalPurpose The purpose
         */
        public function getPurpose(): RetrievalPurpose
        {
            return $this->purpose;
        }

        /**
         * Returns the operator that supplied the URL
         *
         * @return OperatorRecord|null The operator, null if anonymous
         */
        public function getOperator(): ?OperatorRecord
        {
            return $this->operator;
        }

        /**
         * Returns the entity the retrieved file belongs to
         *
         * @return string|null The entity's UUID, null if unknown
         */
        public function getEntityUuid(): ?string
        {
            return $this->entityUuid;
        }

        /**
         * Returns the evidence record the file is attached to
         *
         * @return string|null The evidence's UUID, null if none
         */
        public function getEvidenceUuid(): ?string
        {
            return $this->evidenceUuid;
        }

        /**
         * Returns the index of the evidence item that names the URL
         *
         * @return int|null The index, null if the URL isn't named by a content scan
         */
        public function getEvidenceIndex(): ?int
        {
            return $this->evidenceIndex;
        }

        /**
         * Returns the file index the retrieved file receives
         *
         * @return int|null The index, null if the URL isn't named by a content scan
         */
        public function getFileIndex(): ?int
        {
            return $this->fileIndex;
        }
    }
