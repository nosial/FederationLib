<?php

    namespace FederationLib\Methods\Evidence;

    use FederationLib\Classes\Managers\AuditLogManager;
    use FederationLib\Classes\Managers\EntitiesManager;
    use FederationLib\Classes\Managers\EvidenceManager;
    use FederationLib\Classes\RequestHandler;
    use FederationLib\Classes\Utilities;
    use FederationLib\Enums\AuditLogType;
    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\DatabaseOperationException;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationServer;
    use InvalidArgumentException;

    class SubmitEvidence extends RequestHandler
    {
        /**
         * @inheritDoc
         */
        public static function handleRequest(): void
        {
            $authenticatedOperator = FederationServer::requireAuthenticatedOperator();
            if(!$authenticatedOperator->canManageBlacklist())
            {
                throw new RequestException('You do not have permission to create evidence', 403);
            }

            $entityIdentifier = FederationServer::getParameter('entity_identifier');
            if($entityIdentifier === null)
            {
                throw new RequestException('Entity identifier is required', 400);
            }

            $textContent = FederationServer::getParameter('text_content') ?? null;
            $note = FederationServer::getParameter('note') ?? null;
            $tag = FederationServer::getParameter('tag') ?? null;
            $confidential = (bool)FederationServer::getParameter('confidential') ?? false;

            try
            {
                if(Utilities::isUuid($entityIdentifier))
                {
                    $entityRecord = EntitiesManager::getEntityByUuid($entityIdentifier);
                }
                elseif(Utilities::isSha256($entityIdentifier))
                {
                    $entityRecord = EntitiesManager::getEntityByHash($entityIdentifier);
                }
                elseif(Utilities::isEntityAddress($entityIdentifier))
                {
                    $parsedAddress = Utilities::parseEntityAddress($entityIdentifier);
                    $entityRecord = EntitiesManager::getEntityByHash(Utilities::hashEntity($parsedAddress['host'], $parsedAddress['id']));
                }
                else
                {
                    throw new RequestException('Given identifier is not a valid UUID, SHA-256, or entity address input', 400);
                }

                if($entityRecord === null)
                {
                    throw new RequestException('Entity does not exist', 404);
                }

                $entityUuid = $entityRecord->getUuid();

                $evidenceUuid = EvidenceManager::addEvidence($entityUuid, $authenticatedOperator->getUuid(), $textContent, $note, $tag, $confidential);
                AuditLogManager::createEntry(AuditLogType::EVIDENCE_SUBMITTED, sprintf(
                    'Evidence created by operator %s',
                    $authenticatedOperator->getName()
                ), $authenticatedOperator->getUuid(), $entityUuid, null, $evidenceUuid);
            }
            catch(InvalidArgumentException $e)
            {
                throw new RequestException($e->getMessage(), 400, $e);
            }
            catch (DatabaseOperationException $e)
            {
                throw new RequestException('Failed to create evidence', 500, $e);
            }

            self::successResponse($evidenceUuid, HttpResponseCode::CREATED);
        }
    }
