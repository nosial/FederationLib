<?php

    namespace FederationLib\Tests\MethodEnum;

    use FederationLib\Enums\Method;
    use FederationLib\Interfaces\RequestHandlerInterface;
    use FederationLib\Interfaces\RequestSpecificationInterface;
    use PHPUnit\Framework\TestCase;

    class MethodEnumTest extends TestCase
    {
        private const string VALID_UUID = '550e8400-e29b-41d4-a716-446655440000';
        private const string VALID_SHA256 = 'abcdef1234567890abcdef1234567890abcdef1234567890abcdef1234567890';
        private const string VALID_EMAIL = 'user@example.com';

        public function testAllCasesHaveRouteInfo(): void
        {
            foreach (Method::cases() as $method)
            {
                $routeInfo = $method->getRouteInfo();
                $this->assertIsArray($routeInfo);
                $this->assertCount(3, $routeInfo);
                [$path, $httpMethod, $handlerClass] = $routeInfo;
                $this->assertIsString($path);
                $this->assertIsString($httpMethod);
                $this->assertIsString($handlerClass);
                $this->assertNotEmpty($path);
                $this->assertNotEmpty($httpMethod);
                $this->assertNotEmpty($handlerClass);
            }
        }

        public function testAllHandlerClassesExist(): void
        {
            foreach (Method::cases() as $method)
            {
                [, , $handlerClass] = $method->getRouteInfo();
                $this->assertTrue(class_exists($handlerClass), "Handler class $handlerClass does not exist");
            }
        }

        public function testAllHandlerClassesImplementRequestHandlerInterface(): void
        {
            foreach (Method::cases() as $method)
            {
                [, , $handlerClass] = $method->getRouteInfo();
                $this->assertTrue(
                    is_subclass_of($handlerClass, RequestHandlerInterface::class),
                    "Handler $handlerClass does not implement RequestHandlerInterface"
                );
            }
        }

        public function testAllHandlerClassesImplementRequestSpecificationInterface(): void
        {
            foreach (Method::cases() as $method)
            {
                [, , $handlerClass] = $method->getRouteInfo();
                $this->assertTrue(
                    is_subclass_of($handlerClass, RequestSpecificationInterface::class),
                    "Handler $handlerClass does not implement RequestSpecificationInterface"
                );
            }
        }

        public function testServerRoutesMatchHandle(): void
        {
            $this->assertSame(Method::LIST_AUDIT_LOGS, Method::matchHandle('GET', '/'));
            $this->assertSame(Method::GET_SERVER_INFORMATION, Method::matchHandle('GET', '/info'));
            $this->assertSame(Method::GET_SPECIFICATION, Method::matchHandle('GET', '/specification'));
            $this->assertSame(Method::GET_SPECIFICATION, Method::matchHandle('GET', '/specification.json'));
            $this->assertSame(Method::SCAN_CONTENT, Method::matchHandle('POST', '/scan'));
        }

        public function testAuditRoutesMatchHandle(): void
        {
            $this->assertSame(Method::VIEW_AUDIT_ENTRY, Method::matchHandle('GET', '/audit/' . self::VALID_UUID));
        }

        public function testAttachmentRoutesMatchHandle(): void
        {
            $this->assertSame(Method::LIST_ATTACHMENTS, Method::matchHandle('GET', '/attachments'));
            $this->assertSame(Method::UPLOAD_ATTACHMENT, Method::matchHandle('POST', '/attachments'));
            $this->assertSame(Method::UPLOAD_ATTACHMENT, Method::matchHandle('PUT', '/attachments'));
            $this->assertSame(Method::DOWNLOAD_ATTACHMENT, Method::matchHandle('GET', '/attachments/' . self::VALID_UUID));
            $this->assertSame(Method::DELETE_ATTACHMENT, Method::matchHandle('DELETE', '/attachments/' . self::VALID_UUID));
            $this->assertSame(Method::GET_ATTACHMENT_INFO, Method::matchHandle('GET', '/attachments/' . self::VALID_UUID . '/info'));
        }

        public function testEntityRoutesMatchHandleByUuid(): void
        {
            $uuid = self::VALID_UUID;
            $this->assertSame(Method::LIST_ENTITIES, Method::matchHandle('GET', '/entities'));
            $this->assertSame(Method::PUSH_ENTITY, Method::matchHandle('POST', '/entities'));
            $this->assertSame(Method::GET_ENTITY_RECORD, Method::matchHandle('GET', "/entities/$uuid"));
            $this->assertSame(Method::DELETE_ENTITY, Method::matchHandle('DELETE', "/entities/$uuid"));
            $this->assertSame(Method::LIST_ENTITY_EVIDENCE, Method::matchHandle('GET', "/entities/$uuid/evidence"));
            $this->assertSame(Method::LIST_ENTITY_AUDIT_LOGS, Method::matchHandle('GET', "/entities/$uuid/audit"));
            $this->assertSame(Method::LIST_ENTITY_BLACKLIST_RECORDS, Method::matchHandle('GET', "/entities/$uuid/blacklist"));
            $this->assertSame(Method::CLEAR_REPUTATION, Method::matchHandle('PATCH', "/entities/$uuid/clear-reputation"));
            $this->assertSame(Method::LIST_ENTITY_REPORTS, Method::matchHandle('GET', "/entities/$uuid/reports"));
            $this->assertSame(Method::SET_ENTITY_RELATIONSHIP, Method::matchHandle('PATCH', "/entities/$uuid/relationship"));
            $this->assertSame(Method::CLEAR_ENTITY_RELATIONSHIP, Method::matchHandle('DELETE', "/entities/$uuid/relationship"));
        }

        public function testEntityRoutesMatchHandleBySha256(): void
        {
            $sha256 = self::VALID_SHA256;
            $this->assertSame(Method::GET_ENTITY_RECORD, Method::matchHandle('GET', "/entities/$sha256"));
            $this->assertSame(Method::DELETE_ENTITY, Method::matchHandle('DELETE', "/entities/$sha256"));
            $this->assertSame(Method::LIST_ENTITY_EVIDENCE, Method::matchHandle('GET', "/entities/$sha256/evidence"));
            $this->assertSame(Method::LIST_ENTITY_AUDIT_LOGS, Method::matchHandle('GET', "/entities/$sha256/audit"));
            $this->assertSame(Method::LIST_ENTITY_BLACKLIST_RECORDS, Method::matchHandle('GET', "/entities/$sha256/blacklist"));
            $this->assertSame(Method::CLEAR_REPUTATION, Method::matchHandle('PATCH', "/entities/$sha256/clear-reputation"));
            $this->assertSame(Method::LIST_ENTITY_REPORTS, Method::matchHandle('GET', "/entities/$sha256/reports"));
            $this->assertSame(Method::SET_ENTITY_RELATIONSHIP, Method::matchHandle('PATCH', "/entities/$sha256/relationship"));
            $this->assertSame(Method::CLEAR_ENTITY_RELATIONSHIP, Method::matchHandle('DELETE', "/entities/$sha256/relationship"));
        }

        public function testEntityRoutesMatchHandleByEmail(): void
        {
            $email = self::VALID_EMAIL;
            $this->assertSame(Method::GET_ENTITY_RECORD, Method::matchHandle('GET', "/entities/$email"));
            $this->assertSame(Method::DELETE_ENTITY, Method::matchHandle('DELETE', "/entities/$email"));
            $this->assertSame(Method::LIST_ENTITY_EVIDENCE, Method::matchHandle('GET', "/entities/$email/evidence"));
            $this->assertSame(Method::LIST_ENTITY_AUDIT_LOGS, Method::matchHandle('GET', "/entities/$email/audit"));
            $this->assertSame(Method::LIST_ENTITY_BLACKLIST_RECORDS, Method::matchHandle('GET', "/entities/$email/blacklist"));
            $this->assertSame(Method::CLEAR_REPUTATION, Method::matchHandle('PATCH', "/entities/$email/clear-reputation"));
            $this->assertSame(Method::LIST_ENTITY_REPORTS, Method::matchHandle('GET', "/entities/$email/reports"));
            $this->assertSame(Method::SET_ENTITY_RELATIONSHIP, Method::matchHandle('PATCH', "/entities/$email/relationship"));
            $this->assertSame(Method::CLEAR_ENTITY_RELATIONSHIP, Method::matchHandle('DELETE', "/entities/$email/relationship"));
        }

        public function testBlacklistRoutesMatchHandle(): void
        {
            $this->assertSame(Method::LIST_BLACKLIST, Method::matchHandle('GET', '/blacklist'));
            $this->assertSame(Method::BLACKLIST_ENTITY, Method::matchHandle('POST', '/blacklist'));
            $this->assertSame(Method::GET_BLACKLIST_RECORD, Method::matchHandle('GET', '/blacklist/' . self::VALID_UUID));
            $this->assertSame(Method::DELETE_BLACKLIST, Method::matchHandle('DELETE', '/blacklist/' . self::VALID_UUID));
            $this->assertSame(Method::LIFT_BLACKLIST, Method::matchHandle('PATCH', '/blacklist/' . self::VALID_UUID . '/lift'));
        }

        public function testEvidenceRoutesMatchHandle(): void
        {
            $uuid = self::VALID_UUID;
            $this->assertSame(Method::LIST_EVIDENCE, Method::matchHandle('GET', '/evidence'));
            $this->assertSame(Method::SUBMIT_EVIDENCE, Method::matchHandle('POST', '/evidence'));
            $this->assertSame(Method::GET_EVIDENCE_RECORD, Method::matchHandle('GET', "/evidence/$uuid"));
            $this->assertSame(Method::DELETE_EVIDENCE, Method::matchHandle('DELETE', "/evidence/$uuid"));
            $this->assertSame(Method::GET_EVIDENCE_ATTACHMENTS, Method::matchHandle('GET', "/evidence/$uuid/attachments"));
            $this->assertSame(Method::UPDATE_CONFIDENTIALITY, Method::matchHandle('PATCH', "/evidence/$uuid/update-confidentiality"));
            $this->assertSame(Method::UPDATE_EVIDENCE_TAG, Method::matchHandle('PATCH', "/evidence/$uuid/update-tag"));
            $this->assertSame(Method::ADD_EVIDENCE_TO_REPORT, Method::matchHandle('PATCH', "/evidence/$uuid/link-report"));
        }

        public function testOperatorRoutesMatchHandle(): void
        {
            $uuid = self::VALID_UUID;
            $this->assertSame(Method::LIST_OPERATORS, Method::matchHandle('GET', '/operators'));
            $this->assertSame(Method::CREATE_OPERATOR, Method::matchHandle('POST', '/operators'));
            $this->assertSame(Method::GET_SELF_OPERATOR, Method::matchHandle('GET', '/operators/self'));
            $this->assertSame(Method::GENERATE_OPERATOR_ACCESS_TOKEN, Method::matchHandle('POST', '/operators/refresh'));
            $this->assertSame(Method::GENERATE_OPERATOR_ACCESS_TOKEN, Method::matchHandle('POST', "/operators/$uuid/refresh"));
            $this->assertSame(Method::GET_OPERATOR, Method::matchHandle('GET', "/operators/$uuid"));
            $this->assertSame(Method::DELETE_OPERATOR, Method::matchHandle('DELETE', "/operators/$uuid"));
            $this->assertSame(Method::ENABLE_OPERATOR, Method::matchHandle('PATCH', "/operators/$uuid/enable"));
            $this->assertSame(Method::DISABLE_OPERATOR, Method::matchHandle('PATCH', "/operators/$uuid/disable"));
            $this->assertSame(Method::MANAGE_OPERATOR_PERMISSIONS, Method::matchHandle('PATCH', "/operators/$uuid/operator-permissions"));
            $this->assertSame(Method::MANAGE_MANAGEMENT_PERMISSIONS, Method::matchHandle('PATCH', "/operators/$uuid/management-permissions"));
            $this->assertSame(Method::MANAGE_CLIENT_PERMISSIONS, Method::matchHandle('PATCH', "/operators/$uuid/client-permissions"));
            $this->assertSame(Method::LIST_OPERATOR_EVIDENCE, Method::matchHandle('GET', "/operators/$uuid/evidence"));
            $this->assertSame(Method::LIST_OPERATOR_AUDIT_LOGS, Method::matchHandle('GET', "/operators/$uuid/audit"));
            $this->assertSame(Method::LIST_OPERATOR_BLACKLIST, Method::matchHandle('GET', "/operators/$uuid/blacklist"));
            $this->assertSame(Method::LIST_OPERATOR_REPORTS, Method::matchHandle('GET', "/operators/$uuid/reports"));
            $this->assertSame(Method::LIST_ASSIGNED_OPERATOR_REPORTS, Method::matchHandle('GET', "/operators/$uuid/reports/assigned"));
        }

        public function testReportRoutesMatchHandle(): void
        {
            $uuid = self::VALID_UUID;
            $this->assertSame(Method::LIST_REPORTS, Method::matchHandle('GET', '/reports'));
            $this->assertSame(Method::SUBMIT_REPORT, Method::matchHandle('POST', '/reports'));
            $this->assertSame(Method::GET_REPORT, Method::matchHandle('GET', "/reports/$uuid"));
            $this->assertSame(Method::DELETE_REPORT, Method::matchHandle('DELETE', "/reports/$uuid"));
            $this->assertSame(Method::CLOSE_REPORT, Method::matchHandle('PATCH', "/reports/$uuid/close"));
            $this->assertSame(Method::ASSIGN_OPERATOR_TO_REPORT, Method::matchHandle('PATCH', "/reports/$uuid/assign"));
        }

        public function testMatchHandleWrongMethodReturnsNull(): void
        {
            $this->assertNull(Method::matchHandle('POST', '/info'));
            $this->assertNull(Method::matchHandle('GET', '/scan'));
            $this->assertNull(Method::matchHandle('DELETE', '/evidence'));
            $this->assertNull(Method::matchHandle('PATCH', '/operators'));
        }

        public function testMatchHandleUnknownPathReturnsNull(): void
        {
            $this->assertNull(Method::matchHandle('GET', '/nonexistent'));
            $this->assertNull(Method::matchHandle('POST', '/api/v1/test'));
            $this->assertNull(Method::matchHandle('GET', ''));
        }

        public function testMatchHandleInvalidUuidFormatReturnsNull(): void
        {
            $this->assertNull(Method::matchHandle('GET', '/evidence/not-a-uuid'));
            $this->assertNull(Method::matchHandle('GET', '/evidence/123'));
            $this->assertNull(Method::matchHandle('GET', '/operators/invalid-uuid-format'));
        }

        public function testMatchHandleSpecialPaths(): void
        {
            $this->assertSame(Method::LIST_AUDIT_LOGS, Method::matchHandle('GET', '/'));
            $this->assertNull(Method::matchHandle('POST', '/'));
        }

        public function testGetRouteInfoServerMethods(): void
        {
            $this->assertEquals(['/info', 'get', 'FederationLib\Methods\GetServerInformation'], Method::GET_SERVER_INFORMATION->getRouteInfo());
            $this->assertEquals(['/scan', 'post', 'FederationLib\Methods\ScanContent'], Method::SCAN_CONTENT->getRouteInfo());
            $this->assertEquals(['/specification', 'get', 'FederationLib\Methods\GetSpecification'], Method::GET_SPECIFICATION->getRouteInfo());
        }

        public function testGetRouteInfoEntityMethods(): void
        {
            $this->assertEquals(['/entities', 'get', 'FederationLib\Methods\Entities\ListEntities'], Method::LIST_ENTITIES->getRouteInfo());
            $this->assertEquals(['/entities', 'post', 'FederationLib\Methods\Entities\PushEntity'], Method::PUSH_ENTITY->getRouteInfo());
            $this->assertEquals(['/entities/{identifier}', 'get', 'FederationLib\Methods\Entities\GetEntityRecord'], Method::GET_ENTITY_RECORD->getRouteInfo());
        }

        public function testAllUniquePathsHaveDifferentHandlers(): void
        {
            $entries = [];
            foreach (Method::cases() as $method)
            {
                [$path, $httpMethod] = $method->getRouteInfo();
                $key = "$httpMethod:$path";
                $this->assertArrayNotHasKey(
                    $key,
                    $entries,
                    "Duplicate route [$httpMethod] $path for " . $method->name . " and " . $entries[$key]
                );
                $entries[$key] = $method->name;
            }
        }
    }
