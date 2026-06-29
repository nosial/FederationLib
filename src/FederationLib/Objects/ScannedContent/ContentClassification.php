<?php

    namespace FederationLib\Objects\ScannedContent;

    use FederationLib\Enums\ClassificationFlag;
    use FederationLib\Interfaces\ObjectSpecificationInterface;
    use FederationLib\Interfaces\SerializableInterface;
    use FederationLib\Interfaces\StandardObjectInterface;

    class ContentClassification implements SerializableInterface, ObjectSpecificationInterface
    {
        private ClassificationFlag $classificationFlag;
        private float $confidence;
        private ?string $detectedLanguage;

        /**
         * Public constructor for the ContentClassification
         *
         * @param ClassificationFlag $classificationFlag Content classification flag
         * @param float $confidence The confidence rating of the detected content
         * @param string|null $detectedLanguage Optional. The detected language of the content
         */
        public function __construct(ClassificationFlag $classificationFlag, float $confidence, ?string $detectedLanguage)
        {
            $this->classificationFlag = $classificationFlag;
            $this->confidence = $confidence;
            $this->detectedLanguage = $detectedLanguage;
        }

        /**
         * Returns the ClassificationFlag assigned to the content
         *
         * @return ClassificationFlag The classification flag assigned to the content
         */
        public function getClassificationFlag(): ClassificationFlag
        {
            return $this->classificationFlag;
        }

        /**
         * Returns the confidence score of the detected content
         *
         * @return float The confidence score of the detected content
         */
        public function getConfidence(): float
        {
            return $this->confidence;
        }

        /**
         * Optional. Returns the detected language if supported
         *
         * @return string|null Optional. Returns the detected language if supported
         */
        public function getDetectedLanguage(): ?string
        {
            return $this->detectedLanguage;
        }

        /**
         * @inheritDoc
         */
        public function toArray(): array
        {
            return [
                'classification_flag' => $this->classificationFlag->value,
                'confidence' => $this->confidence,
                'detected_language' => $this->detectedLanguage
            ];
        }

        /**
         * @inheritDoc
         */
        public static function fromArray(array $array): ContentClassification
        {
            return new self(
                ClassificationFlag::tryFrom($array['classification_flag']),
                $array['confidence'],
                $array['detected_language']
            );
        }

        /**
         * @inheritDoc
         */
        public static function getObjectType(): string
        {
            return 'object';
        }

        /**
         * @inheritDoc
         */
        public static function getObjectProperties(): array
        {
            return [
                'classification_flag' => ['type' => 'string', 'description' => 'Classification flag assigned to the content'],
                'confidence' => ['type' => 'number', 'format' => 'float', 'description' => 'Confidence score of the detected content'],
                'detected_language' => ['type' => 'string', 'description' => 'Detected language of the content', 'nullable' => true],
            ];
        }

        /**
         * @inheritDoc
         */
        public static function getObjectRequired(): array
        {
            return ['classification_flag', 'confidence'];
        }

        /**
         * @inheritDoc
         */
        public static function getReference(): string
        {
            return '#/components/schemas/ContentClassification';
        }

        public function __toString(): string
        {
            return sprintf('Detected Classification Type: %s (Confidence: %f%%), content language: %s', $this->classificationFlag->value, $this->confidence, $this->detectedLanguage);
        }
    }