<?php

    namespace FederationLib\Tests\Operators;

    use FederationLib\Enums\HttpResponseCode;
    use FederationLib\Exceptions\RequestException;
    use FederationLib\FederationClient;
    use FederationLib\Helpers\Logger;
    use FederationLib\Helpers\TestHelpers;
    use InvalidArgumentException;
    use PHPUnit\Framework\TestCase;
    use Symfony\Component\Uid\Uuid;

    class OperatorsNameTest extends TestCase
    {
        use TestHelpers;
        private FederationClient $client;
        private array $createdOperators = [];
        private array $createdEntities = [];
        private array $createdEvidenceRecords = [];
        private array $createdBlacklistRecords = [];
        private array $createdReports = [];
        private array $tempFiles = [];

        protected function setUp(): void
        {
            $this->client = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_ACCESS_TOKEN'));
        }

        protected function tearDown(): void
        {
            foreach ($this->createdReports as $reportUuid)
            {
                try
                {
                    $this->client->deleteReport($reportUuid);
                }
                catch (RequestException $e)
                {
                    Logger::getLogger()->warning("Failed to delete report $reportUuid: " . $e->getMessage());
                }
            }

            foreach ($this->createdBlacklistRecords as $blacklistUuid)
            {
                try
                {
                    $this->client->deleteBlacklistRecord($blacklistUuid);
                }
                catch (RequestException $e)
                {
                    Logger::getLogger()->warning("Failed to delete blacklist record $blacklistUuid: " . $e->getMessage());
                }
            }

            foreach ($this->createdEvidenceRecords as $evidenceUuid)
            {
                try
                {
                    $this->client->deleteEvidence($evidenceUuid);
                }
                catch (RequestException $e)
                {
                    Logger::getLogger()->warning("Failed to delete evidence record $evidenceUuid: " . $e->getMessage());
                }
            }

            foreach ($this->createdEntities as $entityUuid)
            {
                try
                {
                    $this->client->deleteEntity($entityUuid);
                }
                catch (RequestException $e)
                {
                    Logger::getLogger()->warning("Failed to delete entity record $entityUuid: " . $e->getMessage());
                }
            }

            foreach ($this->createdOperators as $operatorUuid)
            {
                try
                {
                    $this->client->deleteOperator($operatorUuid);
                }
                catch (RequestException $e)
                {
                    Logger::getLogger()->warning("Failed to delete operator record $operatorUuid: " . $e->getMessage());
                }
            }

            foreach ($this->tempFiles as $tempFile)
            {
                if (file_exists($tempFile))
                {
                    unlink($tempFile);
                }
            }

            $this->createdOperators = [];
            $this->createdEntities = [];
            $this->createdEvidenceRecords = [];
            $this->createdBlacklistRecords = [];
            $this->createdReports = [];
            $this->tempFiles = [];
        }

        public function testUpdateOperatorName(): void
        {
            $operatorUuid = $this->client->createOperator(uniqid('rename_test_'));
            $this->createdOperators[] = $operatorUuid;

            $newName = uniqid('renamed_operator_');
            $this->client->updateOperatorName($operatorUuid, $newName);

            $operatorRecord = $this->client->getOperator($operatorUuid);
            $this->assertEquals($newName, $operatorRecord->getName());
        }

        public function testUpdateOperatorNameEmptyName(): void
        {
            $operatorUuid = $this->client->createOperator(uniqid('empty_name_test_'));
            $this->createdOperators[] = $operatorUuid;

            $this->expectException(InvalidArgumentException::class);
            $this->client->updateOperatorName($operatorUuid, '');
        }

        public function testUpdateOperatorNameTooLongName(): void
        {
            $operatorUuid = $this->client->createOperator(uniqid('long_name_test_'));
            $this->createdOperators[] = $operatorUuid;

            $longName = str_repeat('a', 33);
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::BAD_REQUEST->value);
            $this->client->updateOperatorName($operatorUuid, $longName);
        }

        public function testUpdateOperatorNameEmptyUuid(): void
        {
            $this->expectException(InvalidArgumentException::class);
            $this->client->updateOperatorName('', 'new-name');
        }

        public function testUpdateOperatorNameNonExistentOperator(): void
        {
            $nonExistentUuid = Uuid::v7()->toRfc4122();
            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::NOT_FOUND->value);
            $this->client->updateOperatorName($nonExistentUuid, 'new-name');
        }

        public function testUpdateOperatorNameDuplicateName(): void
        {
            $nameA = uniqid('dup_name_a_');
            $nameB = uniqid('dup_name_b_');
            $operatorAUuid = $this->client->createOperator($nameA);
            $this->createdOperators[] = $operatorAUuid;
            $operatorBUuid = $this->client->createOperator($nameB);
            $this->createdOperators[] = $operatorBUuid;

            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::CONFLICT->value);
            $this->client->updateOperatorName($operatorBUuid, $nameA);
        }

        public function testUpdateOperatorNameRequiresOperatorPermission(): void
        {
            $clientOnly = $this->createLimitedOperator('update_name_client', client: true);
            $targetUuid = $this->client->createOperator(uniqid('update_name_target_'));
            $this->createdOperators[] = $targetUuid;

            $this->expectRequestFailure(
                fn() => $clientOnly->updateOperatorName($targetUuid, 'new-name'),
                [HttpResponseCode::FORBIDDEN->value],
                'Client-only operator should not update operator names'
            );
        }

        public function testUpdateOperatorNameRootOperatorProtected(): void
        {
            $root = $this->client->getSelf();
            $manager = $this->createLimitedOperator('root_name_attacker', operator: true, management: true);

            $this->expectRequestFailure(
                fn() => $manager->updateOperatorName($root->getUuid(), 'new-root-name'),
                [HttpResponseCode::FORBIDDEN->value],
                'Root operator name should be protected from modification'
            );
        }

        public function testUpdateOperatorNameSystemOperatorProtected(): void
        {
            $manager = $this->createLimitedOperator('system_name_attacker', operator: true, management: true);

            $this->expectRequestFailure(
                fn() => $manager->updateOperatorName('00000000-0000-0000-0000-000000000000', 'new-system-name'),
                [HttpResponseCode::FORBIDDEN->value, HttpResponseCode::NOT_FOUND->value],
                'System operator name should be protected from modification'
            );
        }

        public function testUpdateOperatorNameDisabledOperatorCannotUpdateName(): void
        {
            $operatorUuid = $this->client->createOperator(uniqid('dis_upd_name_'));
            $this->createdOperators[] = $operatorUuid;
            $this->client->setOperatorPermissions($operatorUuid, true);

            $operatorClient = new FederationClient(getenv('SERVER_ENDPOINT'), $this->client->getOperator($operatorUuid)->getAccessToken());
            $this->client->disableOperator($operatorUuid);

            $this->expectRequestFailure(
                fn() => $operatorClient->updateOperatorName($operatorUuid, 'new-name'),
                [HttpResponseCode::FORBIDDEN->value],
                'Disabled operator should not be able to update names'
            );
        }

        public function testUpdateOperatorNameSelfModification(): void
        {
            $operator = $this->createLimitedOperator('self_rename', operator: true);
            $selfUuid = $operator->getSelf()->getUuid();
            $newName = uniqid('self_renamed_');

            $operator->updateOperatorName($selfUuid, $newName);

            $updatedRecord = $this->client->getOperator($selfUuid);
            $this->assertEquals($newName, $updatedRecord->getName());
        }

        public function testUpdateOperatorNameCrossOperatorModification(): void
        {
            $manager = $this->createLimitedOperator('cross_rename', operator: true);
            $targetUuid = $this->client->createOperator(uniqid('cross_tgt_'));
            $this->createdOperators[] = $targetUuid;

            $newName = uniqid('cross_renamed_');
            $manager->updateOperatorName($targetUuid, $newName);

            $updatedRecord = $this->client->getOperator($targetUuid);
            $this->assertEquals($newName, $updatedRecord->getName());
        }

        public function testUpdateOperatorNameUnauthenticatedRejected(): void
        {
            $unauthenticatedClient = new FederationClient(getenv('SERVER_ENDPOINT'), null);
            $fakeUuid = '00000000-0000-0000-0000-000000000000';

            $this->expectRequestFailure(
                fn() => $unauthenticatedClient->updateOperatorName($fakeUuid, 'new-name'),
                [HttpResponseCode::UNAUTHORIZED->value, HttpResponseCode::FORBIDDEN->value],
                'Unauthenticated updateOperatorName should be rejected'
            );
        }

        public function testUpdateOperatorNameNameUniqueness(): void
        {
            $nameA = uniqid('unique_name_a_');
            $nameB = uniqid('unique_name_b_');
            $operatorAUuid = $this->client->createOperator($nameA);
            $this->createdOperators[] = $operatorAUuid;
            $operatorBUuid = $this->client->createOperator($nameB);
            $this->createdOperators[] = $operatorBUuid;

            $this->expectException(RequestException::class);
            $this->expectExceptionCode(HttpResponseCode::CONFLICT->value);
            $this->client->updateOperatorName($operatorBUuid, $nameA);
        }

        public function testUpdateOperatorNameNamePreservedAfterFailedAttempt(): void
        {
            $nameA = uniqid('preserve_name_a_');
            $nameB = uniqid('preserve_name_b_');
            $operatorAUuid = $this->client->createOperator($nameA);
            $this->createdOperators[] = $operatorAUuid;
            $operatorBUuid = $this->client->createOperator($nameB);
            $this->createdOperators[] = $operatorBUuid;

            try
            {
                $this->client->updateOperatorName($operatorBUuid, $nameA);
            }
            catch (RequestException)
            {
            }

            $operatorBRecord = $this->client->getOperator($operatorBUuid);
            $this->assertEquals($nameB, $operatorBRecord->getName(), 'Name should be preserved after failed duplicate update');
        }

        public function testUpdateOperatorNameMalformedTokenRejected(): void
        {
            $operatorUuid = $this->client->createOperator(uniqid('mal_tok_ren_'));
            $this->createdOperators[] = $operatorUuid;

            [$code] = $this->rawRequest(
                'PATCH',
                'operators/' . $operatorUuid . '/update-name',
                'invalid_token',
                json_encode(['name' => 'new-name'])
            );

            $this->assertContains(
                $code,
                [HttpResponseCode::BAD_REQUEST->value, HttpResponseCode::UNAUTHORIZED->value],
                'Malformed token should be rejected for updateOperatorName'
            );
        }

        public function testUpdateOperatorNameNameChangeReflectedInList(): void
        {
            $originalName = uniqid('list_orig_');
            $operatorUuid = $this->client->createOperator($originalName);
            $this->createdOperators[] = $operatorUuid;

            $newName = uniqid('list_renamed_');
            $this->client->updateOperatorName($operatorUuid, $newName);

            $operators = $this->client->listOperators(1, 1000);
            $found = false;
            foreach ($operators as $op)
            {
                if ($op->getUuid() === $operatorUuid)
                {
                    $this->assertEquals($newName, $op->getName());
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found, 'Updated operator should appear in operator list with new name');
        }

        public function testUpdateOperatorNameNameChangePersistsAcrossSessions(): void
        {
            $operatorUuid = $this->client->createOperator(uniqid('persist_rename_'));
            $this->createdOperators[] = $operatorUuid;

            $newName = uniqid('persist_renamed_');
            $this->client->updateOperatorName($operatorUuid, $newName);

            $freshClient = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_ACCESS_TOKEN'));
            $operatorRecord = $freshClient->getOperator($operatorUuid);
            $this->assertEquals($newName, $operatorRecord->getName());
        }

        public function testUpdateOperatorNameManagementOnlyCannotUpdateName(): void
        {
            $managementOnly = $this->createLimitedOperator('mgmt_only_rename', management: true);
            $targetUuid = $this->client->createOperator(uniqid('mgmt_rename_target_'));
            $this->createdOperators[] = $targetUuid;

            $this->expectRequestFailure(
                fn() => $managementOnly->updateOperatorName($targetUuid, 'new-name'),
                [HttpResponseCode::FORBIDDEN->value],
                'Management-only operator should not update operator names'
            );
        }

        public function testUpdateOperatorNameNameChangeUpdatesTimestamp(): void
        {
            $operatorUuid = $this->client->createOperator(uniqid('timestamp_rename_'));
            $this->createdOperators[] = $operatorUuid;

            $before = $this->client->getOperator($operatorUuid);
            $originalUpdated = $before->getUpdated();

            sleep(1);

            $newName = uniqid('ts_renamed_');
            $this->client->updateOperatorName($operatorUuid, $newName);

            $after = $this->client->getOperator($operatorUuid);
            $this->assertGreaterThan($originalUpdated, $after->getUpdated(), 'Updated timestamp should increase after name change');
        }

        public function testUpdateOperatorNameNameChangeDoesNotAffectPermissions(): void
        {
            $operatorUuid = $this->client->createOperator(uniqid('perm_preserve_'));
            $this->createdOperators[] = $operatorUuid;
            $this->client->setClientPermissions($operatorUuid, true);
            $this->client->setOperatorPermissions($operatorUuid, true);
            $this->client->setManagementPermissions($operatorUuid, true);

            $before = $this->client->getOperator($operatorUuid);
            $newName = uniqid('perm_renamed_');
            $this->client->updateOperatorName($operatorUuid, $newName);

            $after = $this->client->getOperator($operatorUuid);
            $this->assertEquals($newName, $after->getName());
            $this->assertEquals($before->hasClientPermissions(), $after->hasClientPermissions());
            $this->assertEquals($before->hasOperatorPermissions(), $after->hasOperatorPermissions());
            $this->assertEquals($before->hasManagementPermissions(), $after->hasManagementPermissions());
            $this->assertEquals($before->isDisabled(), $after->isDisabled());
            $this->assertEquals($before->getAccessToken(), $after->getAccessToken());
        }

        public function testUpdateOperatorNameNameChangeInvalidatesCache(): void
        {
            $operatorUuid = $this->client->createOperator(uniqid('cache_inval_'));
            $this->createdOperators[] = $operatorUuid;

            $this->client->getOperator($operatorUuid);

            $newName = uniqid('cache_renamed_');
            $this->client->updateOperatorName($operatorUuid, $newName);

            $freshClient = new FederationClient(getenv('SERVER_ENDPOINT'), getenv('SERVER_ACCESS_TOKEN'));
            $operatorRecord = $freshClient->getOperator($operatorUuid);
            $this->assertEquals($newName, $operatorRecord->getName());
        }
    }
