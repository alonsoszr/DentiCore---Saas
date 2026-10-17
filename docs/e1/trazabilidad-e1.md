# Anexo: trazabilidad de E1 por ID

Generado por `node scripts/traceability/e1.mjs --anexo` (TASK-097). Una prueba cita el ID en `->group(...)` (Pest), en el título, las etiquetas o el comentario previo (Vitest y Playwright), en el docstring (pytest) o en la cabecera del script de carga (k6).

## RF de §17.4 (115)

| ID | Pruebas | Archivos o evidencia |
| --- | --: | --- |
| RF-001 | 1 | `denticore-api/tests/Feature/Isolation/PayloadTenantIdTest.php` |
| RF-002 | 1 | `denticore-api/tests/Unit/Support/TenantScopeTest.php` |
| RF-003 | 4 | `denticore-api/tests/Feature/Isolation/CrossClinicTest.php`<br>`denticore-api/tests/Feature/Isolation/PortalIsolationTest.php`<br>`denticore-api/tests/Feature/Odontogram/CorrectionTest.php`<br>`denticore-api/tests/Feature/Treatment/ProcedureCatalogTest.php` |
| RF-004 | 6 | `denticore-api/tests/Authorization/MatrixTest.php`<br>`denticore-api/tests/Feature/Platform/RegisterClinicTest.php`<br>`denticore-api/tests/Feature/Platform/TenantAdministrationTest.php`<br>`denticore-api/tests/Feature/Scheduling/AffectedAppointmentDecisionsTest.php`<br>`denticore-api/tests/Feature/Scheduling/ScheduleBlockTest.php`<br>`denticore-api/tests/Feature/Scheduling/WorkScheduleTest.php` |
| RF-005 | 2 | `denticore-api/tests/Feature/Isolation/SuperAdminTest.php`<br>`denticore-api/tests/Feature/Support/TenantContextTest.php` |
| RF-006 | 1 | `denticore-api/tests/Feature/Platform/TenantStatusTest.php` |
| RF-007 | 2 | `denticore-api/tests/Feature/Platform/RegisterClinicTest.php`<br>`denticore-api/tests/Feature/Support/HttpContractTest.php` |
| RF-008 | 1 | `denticore-api/tests/Feature/Support/HttpContractTest.php` |
| RF-009 | 2 | `denticore-api/tests/Feature/Support/EvidenceTest.php`<br>`denticore-spa/src/ui/format.test.js` |
| RF-010 | 3 | `denticore-api/tests/Feature/Identity/UserManagementTest.php`<br>`denticore-api/tests/Feature/Patients/PatientSearchTest.php`<br>`denticore-api/tests/Feature/Platform/TenantAdministrationTest.php` |
| RF-011 | 3 | `denticore-api/tests/Feature/Scheduling/AffectedAppointmentDecisionsTest.php`<br>`denticore-api/tests/Feature/Support/TestInfrastructureTest.php`<br>`denticore-api/tests/Feature/Treatment/BudgetDecisionTest.php` |
| RF-012 | 2 | `denticore-api/tests/Feature/Platform/RegisterClinicTest.php`<br>`denticore-api/tests/Feature/Treatment/UrgentProcedureTest.php` |
| RF-013 | 2 | `denticore-api/tests/Feature/Platform/RegisterClinicTest.php`<br>`denticore-api/tests/Feature/Platform/TenantAdministrationTest.php` |
| RF-014 | 4 | `denticore-api/tests/Feature/Platform/PlatformSchemaTest.php`<br>`denticore-api/tests/Feature/Platform/RegisterClinicTest.php`<br>`denticore-api/tests/Feature/Platform/TenantAdministrationTest.php`<br>`denticore-api/tests/Unit/Platform/RucValidatorTest.php` |
| RF-015 | 2 | `denticore-api/tests/Feature/Platform/PlatformSchemaTest.php`<br>`denticore-api/tests/Feature/Platform/RegisterClinicTest.php` |
| RF-016 | 4 | `denticore-api/tests/Feature/Identity/InvitationTest.php`<br>`denticore-api/tests/Feature/Platform/RegisterClinicTest.php`<br>`denticore-api/tests/Feature/Platform/TenantAdministrationTest.php`<br>`denticore-api/tests/Feature/Support/OneTimeTokenTest.php` |
| RF-018 | 2 | `denticore-api/tests/Feature/Platform/TenantAdministrationTest.php`<br>`denticore-spa/src/pages/TenantsPage.test.jsx` |
| RF-019 | 1 | `denticore-api/tests/Feature/Platform/TenantStatusTest.php` |
| RF-022 | 4 | `denticore-api/tests/Feature/Identity/UserManagementTest.php`<br>`denticore-api/tests/Feature/Platform/PlanChangeTest.php`<br>`denticore-api/tests/Feature/Platform/TenantAdministrationTest.php`<br>`denticore-spa/src/pages/TenantDetailPage.test.jsx` |
| RF-023 | 2 | `denticore-api/tests/Feature/Platform/PlanChangeTest.php`<br>`denticore-api/tests/Feature/Platform/PlanLimitTest.php` |
| RF-024 | 2 | `denticore-api/tests/Feature/Platform/ClinicSettingsTest.php`<br>`denticore-spa/src/pages/ClinicSettingsPage.test.jsx` |
| RF-025 | 1 | `denticore-api/tests/Feature/Platform/ClinicSettingsTest.php` |
| RF-026 | 4 | `denticore-api/tests/Feature/Platform/ClinicSettingsTest.php`<br>`denticore-api/tests/Feature/Portal/PortalSubjectsTest.php`<br>`denticore-api/tests/Feature/Portal/SelfBookingTest.php`<br>`denticore-spa/src/pages/ClinicSettingsPage.test.jsx` |
| RF-032 | 1 | `denticore-api/tests/Feature/Identity/LoginTest.php` |
| RF-033 | 3 | `denticore-api/tests/Feature/Identity/LoginTest.php`<br>`denticore-api/tests/Feature/Support/OneTimeTokenTest.php`<br>`denticore-spa/e2e/login.spec.ts` |
| RF-034 | 2 | `denticore-api/tests/Feature/Identity/LoginTest.php`<br>`denticore-api/tests/Feature/Identity/TwoFactorTest.php` |
| RF-035 | 2 | `denticore-api/tests/Feature/Identity/LoginTest.php`<br>`denticore-spa/e2e/login.spec.ts` |
| RF-036 | 2 | `denticore-api/tests/Feature/Identity/LoginTest.php`<br>`denticore-api/tests/Feature/Identity/TokenFreshnessTest.php` |
| RF-037 | 2 | `denticore-api/tests/Feature/Identity/LoginTest.php`<br>`denticore-api/tests/Feature/Identity/TwoFactorTest.php` |
| RF-038 | 2 | `denticore-api/tests/Feature/Identity/IdentitySchemaTest.php`<br>`denticore-api/tests/Feature/Identity/TwoFactorTest.php` |
| RF-039 | 2 | `denticore-api/tests/Feature/Identity/PasswordResetTest.php`<br>`denticore-spa/src/pages/auth/PasswordPages.test.jsx` |
| RF-041 | 2 | `denticore-api/tests/Feature/Identity/PasswordResetTest.php`<br>`denticore-api/tests/Feature/Identity/TokenFreshnessTest.php` |
| RF-042 | 1 | `denticore-api/tests/Feature/Identity/UserManagementTest.php` |
| RF-043 | 2 | `denticore-api/tests/Feature/Identity/IdentitySchemaTest.php`<br>`denticore-api/tests/Feature/Identity/UserManagementTest.php` |
| RF-044 | 2 | `denticore-api/tests/Feature/Identity/TokenFreshnessTest.php`<br>`denticore-api/tests/Feature/Identity/UserManagementTest.php` |
| RF-045 | 1 | `denticore-api/tests/Feature/Identity/UserManagementTest.php` |
| RF-046 | 1 | `denticore-api/tests/Feature/Identity/UserManagementTest.php` |
| RF-047 | 4 | `denticore-api/tests/Feature/Identity/IdentitySchemaTest.php`<br>`denticore-api/tests/Feature/Identity/UserManagementTest.php`<br>`denticore-api/tests/Feature/Patients/ConsentSchemaTest.php`<br>`denticore-api/tests/Feature/Patients/ConsentTest.php` |
| RF-054 | 2 | `denticore-api/tests/Feature/Isolation/CrossClinicTest.php`<br>`denticore-api/tests/Feature/Patients/PatientSearchTest.php` |
| RF-055 | 1 | `denticore-api/tests/Feature/Patients/RegisterPatientTest.php` |
| RF-056 | 2 | `denticore-api/tests/Feature/Patients/PatientSearchTest.php`<br>`denticore-api/tests/Feature/Patients/RegisterPatientTest.php` |
| RF-057 | 1 | `denticore-api/tests/Feature/Patients/RegisterPatientTest.php` |
| RF-058 | 1 | `denticore-api/tests/Feature/Patients/RegisterPatientTest.php` |
| RF-059 | 1 | `denticore-api/tests/Feature/Patients/RegisterPatientTest.php` |
| RF-060 | 1 | `denticore-api/tests/Feature/Patients/LegalRepresentativeTest.php` |
| RF-061 | 5 | `denticore-api/tests/Feature/Isolation/PortalIsolationTest.php`<br>`denticore-api/tests/Feature/Patients/ConsentTest.php`<br>`denticore-api/tests/Feature/Patients/LegalRepresentativeTest.php`<br>`denticore-api/tests/Feature/Patients/RepresentationTest.php`<br>`denticore-api/tests/Feature/Portal/PortalSubjectsTest.php` |
| RF-062 | 2 | `denticore-api/tests/Feature/Patients/PatientSchemaTest.php`<br>`denticore-api/tests/Feature/Patients/RegisterPatientTest.php` |
| RF-064 | 3 | `denticore-api/tests/Feature/Odontogram/ClinicalRecordTest.php`<br>`denticore-api/tests/Feature/Patients/MedicalHistoryTest.php`<br>`denticore-spa/src/pages/patients/PatientSections.test.jsx` |
| RF-065 | 2 | `denticore-api/tests/Feature/Patients/ConsentTest.php`<br>`denticore-api/tests/Feature/Portal/PortalConsentTest.php` |
| RF-066 | 6 | `denticore-api/tests/Feature/Isolation/CrossClinicTest.php`<br>`denticore-api/tests/Feature/Patients/ConsentSchemaTest.php`<br>`denticore-api/tests/Feature/Patients/ConsentTest.php`<br>`denticore-api/tests/Feature/Portal/PortalConsentTest.php`<br>`denticore-api/tests/Feature/Scheduling/NotificationTest.php`<br>`denticore-spa/src/pages/patients/PatientSections.test.jsx` |
| RF-067 | 2 | `denticore-api/tests/Feature/Patients/ConsentTest.php`<br>`denticore-api/tests/Feature/Scheduling/CheckInTest.php` |
| RF-072 | 2 | `denticore-api/tests/Feature/Patients/InformedConsentTest.php`<br>`denticore-api/tests/Feature/Treatment/TreatmentSchemaTest.php` |
| RF-073 | 3 | `denticore-api/tests/Feature/Patients/InformedConsentTest.php`<br>`denticore-api/tests/Feature/Treatment/TreatmentSchemaTest.php`<br>`denticore-spa/src/pages/treatment/Budget.test.jsx` |
| RF-074 | 2 | `denticore-api/tests/Feature/Patients/InformedConsentTest.php`<br>`denticore-api/tests/Feature/Treatment/TreatmentSchemaTest.php` |
| RF-076 | 1 | `denticore-api/tests/Feature/Odontogram/ClinicalRecordTest.php` |
| RF-077 | 2 | `denticore-api/tests/Feature/Odontogram/ClinicalRecordTest.php`<br>`denticore-api/tests/Feature/Odontogram/RecordFindingTest.php` |
| RF-078 | 3 | `denticore-api/tests/Feature/Odontogram/FindingCatalogSchemaTest.php`<br>`denticore-api/tests/Feature/Odontogram/RecordFindingTest.php`<br>`denticore-api/tests/Feature/Treatment/ProcedureCatalogTest.php` |
| RF-079 | 1 | `denticore-api/tests/Feature/Odontogram/ClinicalRecordTest.php` |
| RF-081 | 2 | `denticore-api/tests/Feature/Odontogram/ClinicalRecordTest.php`<br>`denticore-api/tests/Feature/Portal/PortalToothHistoryTest.php` |
| RF-082 | 3 | `denticore-api/tests/Feature/Odontogram/AttentionSchemaTest.php`<br>`denticore-api/tests/Feature/Odontogram/AttentionTest.php`<br>`denticore-spa/src/pages/patients/ClinicalRecordPage.test.jsx` |
| RF-084 | 2 | `denticore-api/tests/Feature/Odontogram/ClinicalNoteTest.php`<br>`denticore-api/tests/Feature/Odontogram/ImmutabilityTest.php` |
| RF-085 | 2 | `denticore-api/tests/Feature/Odontogram/Cie10Test.php`<br>`denticore-api/tests/Feature/Odontogram/ClinicalNoteTest.php` |
| RF-087 | 1 | `denticore-api/tests/Feature/Odontogram/RecordFindingTest.php` |
| RF-088 | 1 | `denticore-api/tests/Feature/Odontogram/RecordFindingTest.php` |
| RF-089 | 1 | `denticore-api/tests/Feature/Odontogram/ImmutabilityTest.php` |
| RF-090 | 2 | `denticore-api/tests/Feature/Odontogram/AttentionTest.php`<br>`denticore-api/tests/Feature/Odontogram/RecordFindingTest.php` |
| RF-091 | 1 | `denticore-api/tests/Feature/Odontogram/RecordFindingTest.php` |
| RF-093 | 1 | `denticore-api/tests/Feature/Odontogram/CorrectionTest.php` |
| RF-094 | 1 | `denticore-api/tests/Feature/Odontogram/AttentionTest.php` |
| RF-096 | 1 | `denticore-api/tests/Feature/Odontogram/AttentionTest.php` |
| RF-097 | 3 | `denticore-api/tests/Feature/Odontogram/AttentionTest.php`<br>`denticore-api/tests/Feature/Odontogram/ClinicalNoteTest.php`<br>`denticore-api/tests/Feature/Odontogram/ImmutabilityTest.php` |
| RF-107 | 2 | `denticore-api/tests/Feature/Treatment/ProcedureCatalogTest.php`<br>`denticore-api/tests/Feature/Treatment/TreatmentSchemaTest.php` |
| RF-109 | 3 | `denticore-api/tests/Feature/Treatment/PlanTest.php`<br>`denticore-api/tests/Feature/Treatment/ProcedureCatalogTest.php`<br>`denticore-spa/src/pages/treatment/Treatment.test.jsx` |
| RF-110 | 3 | `denticore-api/tests/Feature/Treatment/PlanTest.php`<br>`denticore-api/tests/Feature/Treatment/TreatmentSchemaTest.php`<br>`denticore-spa/src/pages/treatment/Treatment.test.jsx` |
| RF-111 | 2 | `denticore-api/tests/Feature/Treatment/PlanTest.php`<br>`denticore-spa/src/pages/treatment/Treatment.test.jsx` |
| RF-112 | 1 | `denticore-api/tests/Feature/Treatment/PlanTest.php` |
| RF-113 | 2 | `denticore-api/tests/Feature/Treatment/PlanTest.php`<br>`denticore-spa/src/pages/treatment/Treatment.test.jsx` |
| RF-114 | 3 | `denticore-api/tests/Feature/Treatment/BudgetDecisionTest.php`<br>`denticore-api/tests/Feature/Treatment/PlanTest.php`<br>`denticore-spa/src/pages/treatment/Treatment.test.jsx` |
| RF-115 | 1 | `denticore-api/tests/Feature/Treatment/IssueBudgetTest.php` |
| RF-116 | 1 | `denticore-api/tests/Feature/Treatment/IssueBudgetTest.php` |
| RF-118 | 2 | `denticore-api/tests/Feature/Isolation/CrossClinicTest.php`<br>`denticore-api/tests/Feature/Treatment/IssueBudgetTest.php` |
| RF-119 | 1 | `denticore-api/tests/Feature/Treatment/IssueBudgetTest.php` |
| RF-120 | 2 | `denticore-api/tests/Feature/Treatment/IssueBudgetTest.php`<br>`denticore-api/tests/Feature/Treatment/TreatmentSchemaTest.php` |
| RF-121 | 3 | `denticore-api/tests/Feature/Portal/BudgetDecisionTest.php`<br>`denticore-api/tests/Feature/Treatment/IssueBudgetTest.php`<br>`denticore-spa/src/pages/treatment/Budget.test.jsx` |
| RF-122 | 1 | `denticore-api/tests/Feature/Treatment/BudgetDecisionTest.php` |
| RF-123 | 2 | `denticore-api/tests/Feature/Portal/BudgetDecisionTest.php`<br>`denticore-api/tests/Feature/Treatment/BudgetDecisionTest.php` |
| RF-124 | 2 | `denticore-api/tests/Feature/Isolation/CrossClinicTest.php`<br>`denticore-api/tests/Feature/Treatment/ExpireBudgetsTest.php` |
| RF-126 | 1 | `denticore-api/tests/Feature/Treatment/PerformedProcedureTest.php` |
| RF-127 | 1 | `denticore-api/tests/Feature/Patients/InformedConsentTest.php` |
| RF-128 | 1 | `denticore-api/tests/Feature/Treatment/UrgentProcedureTest.php` |
| RF-129 | 3 | `denticore-api/tests/Feature/Treatment/PlanTest.php`<br>`denticore-api/tests/Feature/Treatment/TreatmentSchemaTest.php`<br>`denticore-spa/src/pages/treatment/Treatment.test.jsx` |
| RF-142 | 4 | `denticore-api/tests/Feature/Scheduling/AffectedAppointmentDecisionsTest.php`<br>`denticore-api/tests/Feature/Scheduling/SchedulingSchemaTest.php`<br>`denticore-api/tests/Feature/Scheduling/WorkScheduleTest.php`<br>`denticore-api/tests/Unit/Scheduling/AvailabilityCalculatorTest.php` |
| RF-143 | 1 | `denticore-api/tests/Feature/Scheduling/ScheduleBlockTest.php` |
| RF-144 | 3 | `denticore-api/tests/Feature/Scheduling/AffectedAppointmentDecisionsTest.php`<br>`denticore-api/tests/Feature/Scheduling/ScheduleBlockTest.php`<br>`denticore-api/tests/Feature/Scheduling/WorkScheduleTest.php` |
| RF-145 | 1 | `denticore-api/tests/Feature/Scheduling/AppointmentTypeTest.php` |
| RF-146 | 3 | `denticore-api/tests/Feature/Portal/SelfBookingTest.php`<br>`denticore-api/tests/Feature/Scheduling/AvailabilityTest.php`<br>`denticore-api/tests/Unit/Scheduling/AvailabilityCalculatorTest.php` |
| RF-147 | 1 | `denticore-api/tests/Feature/Scheduling/BookAppointmentTest.php` |
| RF-149 | 3 | `denticore-api/tests/Feature/Portal/CancelAppointmentTest.php`<br>`denticore-api/tests/Feature/Scheduling/BookAppointmentTest.php`<br>`denticore-api/tests/Feature/Scheduling/SchedulingSchemaTest.php` |
| RF-150 | 3 | `denticore-api/tests/Feature/Isolation/CrossClinicTest.php`<br>`denticore-api/tests/Feature/Portal/CancelAppointmentTest.php`<br>`denticore-api/tests/Feature/Scheduling/BookAppointmentTest.php` |
| RF-151 | 2 | `denticore-api/tests/Feature/Odontogram/AttentionTest.php`<br>`denticore-api/tests/Feature/Scheduling/CheckInTest.php` |
| RF-152 | 1 | `denticore-api/tests/Feature/Scheduling/NoShowTest.php` |
| RF-153 | 1 | `denticore-api/tests/Feature/Scheduling/AgendaTest.php` |
| RF-155 | 4 | `denticore-api/tests/Feature/Isolation/CrossClinicTest.php`<br>`denticore-api/tests/Feature/Scheduling/BookAppointmentTest.php`<br>`denticore-api/tests/Feature/Scheduling/NotificationTest.php`<br>`denticore-api/tests/Feature/Treatment/IssueBudgetTest.php` |
| RF-156 | 1 | `denticore-api/tests/Feature/Scheduling/ReminderTest.php` |
| RF-158 | 1 | `denticore-api/tests/Feature/Scheduling/NotificationInboxTest.php` |
| RF-161 | 1 | `denticore-api/tests/Feature/Risk/RiskVariableTest.php` |
| RF-162 | 2 | `denticore-api/tests/Contract/RiskVisibilityTest.php`<br>`denticore-api/tests/Feature/Risk/RiskVariableTest.php` |
| RF-164 | 3 | `denticore-api/tests/Feature/Risk/PredictTest.php`<br>`denticore-api/tests/Feature/Risk/RiskSchemaTest.php`<br>`denticore-api/tests/Feature/Risk/RiskVariableTest.php` |
| RF-165 | 1 | `denticore-api/tests/Feature/Risk/PredictTest.php` |
| RF-166 | 2 | `denticore-api/tests/Feature/Risk/CircuitBreakerTest.php`<br>`denticore-api/tests/Feature/Risk/RiskEngineClientTest.php` |
| RF-167 | 3 | `denticore-api/tests/Contract/MlContractTest.php`<br>`denticore-api/tests/Feature/Risk/PredictTest.php`<br>`denticore-ml/tests/test_predict.py` |
| RF-168 | 1 | `denticore-api/tests/Feature/Risk/PredictTest.php` |
| RF-170 | 1 | `denticore-api/tests/Feature/Risk/PredictTest.php` |
| RF-171 | 2 | `denticore-api/tests/Feature/Risk/AlertTest.php`<br>`denticore-api/tests/Feature/Risk/RiskSchemaTest.php` |
| RF-186 | 2 | `denticore-api/tests/Feature/Compliance/AuditTest.php`<br>`denticore-api/tests/Feature/Risk/RiskVariableTest.php` |

## RN de los CUS de E1 (§14.5) (64)

| ID | Pruebas | Archivos o evidencia |
| --- | --: | --- |
| RN-01 | 7 | `denticore-api/tests/Feature/Identity/LoginTest.php`<br>`denticore-api/tests/Feature/Isolation/CrossClinicTest.php`<br>`denticore-api/tests/Feature/Isolation/PayloadTenantIdTest.php`<br>`denticore-api/tests/Feature/Patients/ConsentSchemaTest.php`<br>`denticore-api/tests/Feature/Patients/PatientSchemaTest.php`<br>`denticore-api/tests/Feature/Support/TenantContextTest.php`<br>`denticore-api/tests/Feature/Treatment/ProcedureCatalogTest.php` |
| RN-02 | 2 | `denticore-api/tests/Feature/Support/TenantContextTest.php`<br>`denticore-api/tests/Unit/Support/TenantScopeTest.php` |
| RN-03 | 11 | `denticore-api/tests/Feature/Isolation/CrossClinicTest.php`<br>`denticore-api/tests/Feature/Isolation/PortalIsolationTest.php`<br>`denticore-api/tests/Feature/Patients/ConsentTest.php`<br>`denticore-api/tests/Feature/Patients/InformedConsentTest.php`<br>`denticore-api/tests/Feature/Patients/RegisterPatientTest.php`<br>`denticore-api/tests/Feature/Portal/BudgetDecisionTest.php`<br>`denticore-api/tests/Feature/Portal/CancelAppointmentTest.php`<br>`denticore-api/tests/Feature/Portal/PortalConsentTest.php`<br>`denticore-api/tests/Feature/Portal/PortalSubjectsTest.php`<br>`denticore-api/tests/Feature/Portal/PortalToothHistoryTest.php`<br>`denticore-api/tests/Feature/Treatment/ProcedureCatalogTest.php` |
| RN-04 | 4 | `denticore-api/tests/Feature/Identity/IdentitySchemaTest.php`<br>`denticore-api/tests/Feature/Isolation/SuperAdminTest.php`<br>`denticore-api/tests/Feature/Platform/RegisterClinicTest.php`<br>`denticore-api/tests/Feature/Platform/TenantAdministrationTest.php` |
| RN-05 | 4 | `denticore-api/tests/Feature/Identity/IdentitySchemaTest.php`<br>`denticore-api/tests/Feature/Identity/LoginTest.php`<br>`denticore-api/tests/Feature/Identity/UserManagementTest.php`<br>`denticore-api/tests/Feature/Platform/RegisterClinicTest.php` |
| RN-06 | 9 | `denticore-api/tests/Authorization/MatrixTest.php`<br>`denticore-api/tests/Feature/Isolation/PortalIsolationTest.php`<br>`denticore-api/tests/Feature/Patients/LegalRepresentativeTest.php`<br>`denticore-api/tests/Feature/Patients/MedicalHistoryTest.php`<br>`denticore-api/tests/Feature/Patients/RegisterPatientTest.php`<br>`denticore-api/tests/Feature/Platform/ClinicSettingsTest.php`<br>`denticore-api/tests/Feature/Portal/PortalSubjectsTest.php`<br>`denticore-api/tests/Feature/Treatment/BudgetDecisionTest.php`<br>`denticore-api/tests/Feature/Treatment/ProcedureCatalogTest.php` |
| RN-07 | 3 | `denticore-api/tests/Feature/Identity/LoginTest.php`<br>`denticore-api/tests/Feature/Platform/TenantStatusTest.php`<br>`denticore-api/tests/Feature/Scheduling/ReminderTest.php` |
| RN-08 | 6 | `denticore-api/tests/Feature/Identity/UserManagementTest.php`<br>`denticore-api/tests/Feature/Patients/ConsentTest.php`<br>`denticore-api/tests/Feature/Platform/PlanChangeTest.php`<br>`denticore-api/tests/Feature/Platform/PlanLimitTest.php`<br>`denticore-api/tests/Feature/Platform/PlatformSchemaTest.php`<br>`denticore-api/tests/Feature/Risk/RiskVariableTest.php` |
| RN-09 | 3 | `denticore-api/tests/Feature/Patients/PatientSchemaTest.php`<br>`denticore-api/tests/Feature/Patients/PatientSearchTest.php`<br>`denticore-api/tests/Feature/Patients/RegisterPatientTest.php` |
| RN-10 | 9 | `denticore-api/tests/Feature/Odontogram/AttentionTest.php`<br>`denticore-api/tests/Feature/Odontogram/ClinicalNoteTest.php`<br>`denticore-api/tests/Feature/Odontogram/RecordFindingTest.php`<br>`denticore-api/tests/Feature/Patients/ConsentSchemaTest.php`<br>`denticore-api/tests/Feature/Patients/ConsentTest.php`<br>`denticore-api/tests/Feature/Patients/MedicalHistoryTest.php`<br>`denticore-api/tests/Feature/Portal/PortalConsentTest.php`<br>`denticore-api/tests/Feature/Scheduling/CheckInTest.php`<br>`denticore-spa/src/pages/patients/PatientSections.test.jsx` |
| RN-11 | 2 | `denticore-api/tests/Feature/Patients/ConsentSchemaTest.php`<br>`denticore-api/tests/Feature/Patients/ConsentTest.php` |
| RN-12 | 14 | `denticore-api/tests/Feature/Patients/ConsentSchemaTest.php`<br>`denticore-api/tests/Feature/Patients/ConsentTest.php`<br>`denticore-api/tests/Feature/Patients/InformedConsentTest.php`<br>`denticore-api/tests/Feature/Patients/LegalRepresentativeTest.php`<br>`denticore-api/tests/Feature/Patients/PatientSchemaTest.php`<br>`denticore-api/tests/Feature/Patients/RegisterPatientTest.php`<br>`denticore-api/tests/Feature/Portal/BudgetDecisionTest.php`<br>`denticore-api/tests/Feature/Portal/PortalConsentTest.php`<br>`denticore-api/tests/Feature/Portal/PortalSubjectsTest.php`<br>`denticore-api/tests/Feature/Risk/RiskVariableTest.php`<br>`denticore-api/tests/Feature/Treatment/BudgetDecisionTest.php`<br>`denticore-api/tests/Feature/Treatment/TreatmentSchemaTest.php`<br>`denticore-spa/src/portal/Portal.test.jsx`<br>`denticore-spa/src/portal/PortalCare.test.jsx` |
| RN-13 | 6 | `denticore-api/tests/Feature/Isolation/CrossClinicTest.php`<br>`denticore-api/tests/Feature/Isolation/PortalIsolationTest.php`<br>`denticore-api/tests/Feature/Patients/ConsentTest.php`<br>`denticore-api/tests/Feature/Patients/LegalRepresentativeTest.php`<br>`denticore-api/tests/Feature/Patients/RepresentationTest.php`<br>`denticore-api/tests/Feature/Portal/PortalSubjectsTest.php` |
| RN-15 | 3 | `denticore-api/tests/Feature/Odontogram/AttentionTest.php`<br>`denticore-api/tests/Feature/Patients/ConsentSchemaTest.php`<br>`denticore-api/tests/Feature/Patients/ConsentTest.php` |
| RN-16 | 5 | `denticore-api/tests/Feature/Odontogram/AttentionSchemaTest.php`<br>`denticore-api/tests/Feature/Odontogram/ClinicalValidatorDatabaseTest.php`<br>`denticore-api/tests/Feature/Odontogram/RecordFindingTest.php`<br>`denticore-api/tests/Feature/Support/SqlHelperObjectsTest.php`<br>`denticore-api/tests/Unit/Odontogram/ClinicalValidatorTest.php` |
| RN-17 | 7 | `denticore-api/tests/Feature/Odontogram/AttentionSchemaTest.php`<br>`denticore-api/tests/Feature/Odontogram/FindingCatalogSchemaTest.php`<br>`denticore-api/tests/Feature/Odontogram/NtsCatalogSeedTest.php`<br>`denticore-api/tests/Feature/Odontogram/RecordFindingTest.php`<br>`denticore-api/tests/Feature/Support/SqlHelperObjectsTest.php`<br>`denticore-api/tests/Unit/Odontogram/ClinicalValidatorTest.php`<br>`denticore-spa/e2e/attention.spec.ts` |
| RN-18 | 7 | `denticore-api/tests/Feature/Odontogram/AttentionSchemaTest.php`<br>`denticore-api/tests/Feature/Odontogram/ClinicalValidatorDatabaseTest.php`<br>`denticore-api/tests/Feature/Odontogram/NtsCatalogSeedTest.php`<br>`denticore-api/tests/Feature/Odontogram/RecordFindingTest.php`<br>`denticore-api/tests/Feature/Support/SqlHelperObjectsTest.php`<br>`denticore-api/tests/Unit/Odontogram/ClinicalValidatorTest.php`<br>`denticore-spa/src/components/odontogram/teeth.test.js` |
| RN-19 | 3 | `denticore-api/tests/Feature/Odontogram/CorrectionTest.php`<br>`denticore-api/tests/Feature/Odontogram/RecordFindingTest.php`<br>`denticore-api/tests/Feature/Treatment/PerformedProcedureTest.php` |
| RN-20 | 5 | `denticore-api/tests/Feature/Isolation/CrossClinicTest.php`<br>`denticore-api/tests/Feature/Odontogram/AttentionTest.php`<br>`denticore-api/tests/Feature/Odontogram/ClinicalRecordTest.php`<br>`denticore-api/tests/Feature/Odontogram/ImmutabilityTest.php`<br>`denticore-api/tests/Feature/Odontogram/RecordFindingTest.php` |
| RN-21 | 1 | `denticore-api/tests/Feature/Odontogram/RecordFindingTest.php` |
| RN-22 | 4 | `denticore-api/tests/Feature/Odontogram/ImmutabilityTest.php`<br>`denticore-api/tests/Feature/Odontogram/RecordFindingTest.php`<br>`denticore-api/tests/Feature/Support/SchemaIntegrityTest.php`<br>`denticore-api/tests/Feature/Support/SqlHelperObjectsTest.php` |
| RN-23 | 2 | `denticore-api/tests/Feature/Odontogram/AttentionSchemaTest.php`<br>`denticore-api/tests/Feature/Odontogram/CorrectionTest.php` |
| RN-24 | 2 | `denticore-api/tests/Feature/Odontogram/ClinicalRecordTest.php`<br>`denticore-api/tests/Feature/Odontogram/CorrectionTest.php` |
| RN-25 | 2 | `denticore-api/tests/Feature/Odontogram/RecordFindingTest.php`<br>`denticore-api/tests/Unit/Odontogram/ClinicalValidatorTest.php` |
| RN-26 | 4 | `denticore-api/tests/Feature/Treatment/PlanTest.php`<br>`denticore-api/tests/Feature/Treatment/ProcedureCatalogTest.php`<br>`denticore-api/tests/Feature/Treatment/TreatmentSchemaTest.php`<br>`denticore-api/tests/Feature/Treatment/UrgentProcedureTest.php` |
| RN-27 | 2 | `denticore-api/tests/Feature/Treatment/PlanTest.php`<br>`denticore-api/tests/Feature/Treatment/TreatmentSchemaTest.php` |
| RN-28 | 1 | `denticore-api/tests/Feature/Treatment/IssueBudgetTest.php` |
| RN-29 | 3 | `denticore-api/tests/Feature/Treatment/IssueBudgetTest.php`<br>`denticore-api/tests/Feature/Treatment/TreatmentSchemaTest.php`<br>`denticore-api/tests/Unit/Treatment/BudgetCalculatorTest.php` |
| RN-30 | 5 | `denticore-api/tests/Feature/Platform/PlatformSchemaTest.php`<br>`denticore-api/tests/Feature/Treatment/IssueBudgetTest.php`<br>`denticore-api/tests/Feature/Treatment/PlanTest.php`<br>`denticore-api/tests/Feature/Treatment/TreatmentSchemaTest.php`<br>`denticore-api/tests/Unit/Treatment/BudgetCalculatorTest.php` |
| RN-31 | 5 | `denticore-api/tests/Feature/Platform/ClinicSettingsTest.php`<br>`denticore-api/tests/Feature/Platform/PlatformSchemaTest.php`<br>`denticore-api/tests/Feature/Treatment/IssueBudgetTest.php`<br>`denticore-api/tests/Feature/Treatment/TreatmentSchemaTest.php`<br>`denticore-spa/src/pages/treatment/Budget.test.jsx` |
| RN-32 | 2 | `denticore-api/tests/Feature/Treatment/IssueBudgetTest.php`<br>`denticore-spa/src/pages/treatment/Budget.test.jsx` |
| RN-33 | 2 | `denticore-api/tests/Feature/Treatment/IssueBudgetTest.php`<br>`denticore-api/tests/Feature/Treatment/ProcedureCatalogTest.php` |
| RN-34 | 2 | `denticore-api/tests/Feature/Treatment/IssueBudgetTest.php`<br>`denticore-api/tests/Feature/Treatment/TreatmentSchemaTest.php` |
| RN-35 | 8 | `denticore-api/tests/Feature/Platform/ClinicSettingsTest.php`<br>`denticore-api/tests/Feature/Platform/PlatformSchemaTest.php`<br>`denticore-api/tests/Feature/Treatment/BudgetDecisionTest.php`<br>`denticore-api/tests/Feature/Treatment/ExpireBudgetsTest.php`<br>`denticore-api/tests/Feature/Treatment/IssueBudgetTest.php`<br>`denticore-api/tests/Feature/Treatment/TreatmentSchemaTest.php`<br>`denticore-spa/src/pages/treatment/Budget.test.jsx`<br>`denticore-spa/src/portal/Portal.test.jsx` |
| RN-36 | 5 | `denticore-api/tests/Feature/Portal/BudgetDecisionTest.php`<br>`denticore-api/tests/Feature/Treatment/BudgetDecisionTest.php`<br>`denticore-api/tests/Feature/Treatment/IssueBudgetTest.php`<br>`denticore-api/tests/Feature/Treatment/TreatmentSchemaTest.php`<br>`denticore-spa/src/pages/treatment/Budget.test.jsx` |
| RN-37 | 2 | `denticore-api/tests/Feature/Treatment/BudgetDecisionTest.php`<br>`denticore-api/tests/Feature/Treatment/TreatmentSchemaTest.php` |
| RN-38 | 3 | `denticore-api/tests/Feature/Treatment/PerformedProcedureTest.php`<br>`denticore-api/tests/Feature/Treatment/TreatmentSchemaTest.php`<br>`denticore-api/tests/Feature/Treatment/UrgentProcedureTest.php` |
| RN-39 | 2 | `denticore-api/tests/Feature/Treatment/PerformedProcedureTest.php`<br>`denticore-api/tests/Feature/Treatment/ProcedureCatalogTest.php` |
| RN-46 | 6 | `denticore-api/tests/Feature/Scheduling/AffectedAppointmentDecisionsTest.php`<br>`denticore-api/tests/Feature/Scheduling/AvailabilityTest.php`<br>`denticore-api/tests/Feature/Scheduling/BookAppointmentTest.php`<br>`denticore-api/tests/Feature/Scheduling/SchedulingSchemaTest.php`<br>`denticore-api/tests/Unit/Scheduling/AvailabilityCalculatorTest.php`<br>`load/pc-e.js` |
| RN-47 | 5 | `denticore-api/tests/Feature/Scheduling/AppointmentTypeTest.php`<br>`denticore-api/tests/Feature/Scheduling/AvailabilityTest.php`<br>`denticore-api/tests/Feature/Scheduling/BookAppointmentTest.php`<br>`denticore-api/tests/Feature/Scheduling/SchedulingSchemaTest.php`<br>`denticore-api/tests/Unit/Scheduling/AvailabilityCalculatorTest.php` |
| RN-48 | 4 | `denticore-api/tests/Feature/Portal/SelfBookingTest.php`<br>`denticore-api/tests/Feature/Scheduling/AvailabilityTest.php`<br>`denticore-api/tests/Feature/Scheduling/BookAppointmentTest.php`<br>`denticore-api/tests/Unit/Scheduling/AvailabilityCalculatorTest.php` |
| RN-49 | 4 | `denticore-api/tests/Feature/Platform/ClinicSettingsTest.php`<br>`denticore-api/tests/Feature/Platform/PlatformSchemaTest.php`<br>`denticore-api/tests/Feature/Portal/CancelAppointmentTest.php`<br>`denticore-spa/src/portal/PortalCare.test.jsx` |
| RN-50 | 1 | `denticore-api/tests/Feature/Scheduling/CheckInTest.php` |
| RN-51 | 2 | `denticore-api/tests/Feature/Isolation/CrossClinicTest.php`<br>`denticore-api/tests/Feature/Scheduling/NoShowTest.php` |
| RN-52 | 3 | `denticore-api/tests/Feature/Isolation/CrossClinicTest.php`<br>`denticore-api/tests/Feature/Scheduling/BookAppointmentTest.php`<br>`denticore-api/tests/Feature/Scheduling/ReminderTest.php` |
| RN-53 | 1 | `denticore-api/tests/Feature/Patients/ConsentTest.php` |
| RN-58 | 3 | `denticore-api/tests/Feature/Patients/ConsentTest.php`<br>`denticore-api/tests/Feature/Risk/PredictTest.php`<br>`denticore-api/tests/Feature/Risk/RiskVariableTest.php` |
| RN-59 | 3 | `denticore-api/tests/Feature/Risk/PredictTest.php`<br>`denticore-api/tests/Feature/Risk/RiskSchemaTest.php`<br>`denticore-ml/tests/test_training.py` |
| RN-60 | 4 | `denticore-api/tests/Feature/Isolation/CrossClinicTest.php`<br>`denticore-api/tests/Feature/Risk/PredictTest.php`<br>`denticore-api/tests/Feature/Risk/RiskSchemaTest.php`<br>`denticore-api/tests/Feature/Risk/RiskVariableTest.php` |
| RN-61 | 2 | `denticore-api/tests/Feature/Risk/PredictTest.php`<br>`denticore-api/tests/Feature/Risk/RiskSchemaTest.php` |
| RN-62 | 1 | `denticore-api/tests/Feature/Risk/PredictTest.php` |
| RN-63 | 1 | `denticore-api/tests/Feature/Risk/PredictTest.php` |
| RN-64 | 4 | `denticore-api/tests/Feature/Risk/CircuitBreakerTest.php`<br>`denticore-api/tests/Feature/Risk/PredictTest.php`<br>`denticore-api/tests/Feature/Risk/RiskEngineClientTest.php`<br>`denticore-spa/e2e/risk.spec.ts` |
| RN-65 | 2 | `denticore-api/tests/Feature/Risk/AlertTest.php`<br>`denticore-api/tests/Feature/Risk/RiskSchemaTest.php` |
| RN-67 | 5 | `denticore-api/tests/Feature/Compliance/AuditTest.php`<br>`denticore-api/tests/Feature/Identity/LoginTest.php`<br>`denticore-api/tests/Feature/Odontogram/ClinicalRecordTest.php`<br>`denticore-api/tests/Feature/Patients/RegisterPatientTest.php`<br>`denticore-api/tests/Feature/Platform/RegisterClinicTest.php` |
| RN-68 | 1 | `denticore-api/tests/Feature/Odontogram/AttentionTest.php` |
| RN-69 | 2 | `denticore-api/tests/Feature/Patients/PatientSchemaTest.php`<br>`denticore-api/tests/Feature/Patients/RegisterPatientTest.php` |
| RN-75 | 4 | `denticore-api/tests/Feature/Identity/IdentitySchemaTest.php`<br>`denticore-api/tests/Feature/Identity/UserManagementTest.php`<br>`denticore-api/tests/Feature/Odontogram/AttentionTest.php`<br>`denticore-api/tests/Feature/Odontogram/RecordFindingTest.php` |
| RN-76 | 2 | `denticore-api/tests/Feature/Patients/InformedConsentTest.php`<br>`denticore-api/tests/Feature/Treatment/TreatmentSchemaTest.php` |
| RN-77 | 3 | `denticore-api/tests/Feature/Odontogram/AttentionTest.php`<br>`denticore-api/tests/Feature/Odontogram/ImmutabilityTest.php`<br>`denticore-spa/src/pages/attentions/AttentionPage.test.jsx` |
| RN-78 | 3 | `denticore-api/tests/Feature/Odontogram/AttentionTest.php`<br>`denticore-api/tests/Feature/Odontogram/ClinicalNoteTest.php`<br>`denticore-api/tests/Feature/Odontogram/ImmutabilityTest.php` |
| RN-79 | 2 | `denticore-api/tests/Feature/Patients/PatientSchemaTest.php`<br>`denticore-api/tests/Feature/Patients/RegisterPatientTest.php` |
| RN-80 | 2 | `denticore-api/tests/Feature/Portal/PortalSubjectsTest.php`<br>`denticore-api/tests/Feature/Scheduling/BookAppointmentTest.php` |
| RN-82 | 4 | `denticore-api/tests/Feature/Scheduling/AffectedAppointmentDecisionsTest.php`<br>`denticore-api/tests/Feature/Scheduling/ScheduleBlockTest.php`<br>`denticore-api/tests/Feature/Scheduling/WorkScheduleTest.php`<br>`denticore-spa/src/pages/scheduling/SchedulingConfig.test.jsx` |

## CA de §11 de los CUS de E1 (59)

| ID | Pruebas | Archivos o evidencia |
| --- | --: | --- |
| CA-01.1 | 2 | `denticore-api/tests/Feature/Identity/IdentitySchemaTest.php`<br>`denticore-api/tests/Feature/Platform/RegisterClinicTest.php` |
| CA-01.2 | 1 | `denticore-api/tests/Feature/Platform/RegisterClinicTest.php` |
| CA-01.3 | 1 | `denticore-api/tests/Feature/Platform/RegisterClinicTest.php` |
| CA-01.4 | 1 | `denticore-api/tests/Feature/Platform/RegisterClinicTest.php` |
| CA-01.5 | 2 | `denticore-api/tests/Feature/Identity/InvitationTest.php`<br>`denticore-api/tests/Feature/Platform/TenantAdministrationTest.php` |
| CA-06.1 | 1 | `denticore-api/tests/Feature/Identity/LoginTest.php` |
| CA-06.2 | 1 | `denticore-api/tests/Feature/Identity/LoginTest.php` |
| CA-06.3 | 3 | `denticore-api/tests/Feature/Identity/LoginTest.php`<br>`denticore-spa/e2e/login.spec.ts`<br>`denticore-spa/src/pages/LoginPage.test.jsx` |
| CA-06.4 | 1 | `denticore-api/tests/Feature/Identity/TwoFactorTest.php` |
| CA-06.5 | 1 | `denticore-api/tests/Feature/Identity/TokenFreshnessTest.php` |
| CA-06.6 | 1 | `denticore-api/tests/Feature/Platform/TenantStatusTest.php` |
| CA-14.1 | 1 | `denticore-api/tests/Feature/Patients/RegisterPatientTest.php` |
| CA-14.2 | 1 | `denticore-api/tests/Feature/Patients/RegisterPatientTest.php` |
| CA-14.3 | 1 | `denticore-api/tests/Feature/Patients/RegisterPatientTest.php` |
| CA-14.4 | 1 | `denticore-api/tests/Feature/Patients/RegisterPatientTest.php` |
| CA-14.5 | 1 | `denticore-api/tests/Feature/Patients/MedicalHistoryTest.php` |
| CA-17.1 | 2 | `denticore-api/tests/Feature/Patients/ConsentTest.php`<br>`denticore-spa/src/portal/ConsentForm.test.jsx` |
| CA-17.2 | 2 | `denticore-api/tests/Feature/Patients/ConsentSchemaTest.php`<br>`denticore-api/tests/Feature/Patients/ConsentTest.php` |
| CA-17.3 | 3 | `denticore-api/tests/Feature/Patients/ConsentSchemaTest.php`<br>`denticore-api/tests/Feature/Patients/ConsentTest.php`<br>`denticore-api/tests/Feature/Portal/PortalConsentTest.php` |
| CA-17.4 | 2 | `denticore-api/tests/Feature/Patients/ConsentSchemaTest.php`<br>`denticore-api/tests/Feature/Patients/ConsentTest.php` |
| CA-17.5 | 2 | `denticore-api/tests/Feature/Patients/ConsentTest.php`<br>`denticore-spa/src/portal/ConsentForm.test.jsx` |
| CA-22.1 | 1 | `denticore-api/tests/Feature/Odontogram/RecordFindingTest.php` |
| CA-22.2 | 1 | `denticore-api/tests/Feature/Odontogram/RecordFindingTest.php` |
| CA-22.3 | 1 | `denticore-api/tests/Feature/Odontogram/RecordFindingTest.php` |
| CA-22.4 | 1 | `denticore-api/tests/Feature/Odontogram/RecordFindingTest.php` |
| CA-22.5 | 1 | `denticore-api/tests/Feature/Odontogram/ImmutabilityTest.php` |
| CA-22.6 | 1 | `denticore-api/tests/Feature/Odontogram/RecordFindingTest.php` |
| CA-23.1 | 2 | `denticore-api/tests/Feature/Odontogram/ClinicalRecordTest.php`<br>`denticore-api/tests/Feature/Odontogram/CorrectionTest.php` |
| CA-23.2 | 1 | `denticore-api/tests/Feature/Odontogram/CorrectionTest.php` |
| CA-23.3 | 2 | `denticore-api/tests/Feature/Odontogram/AttentionSchemaTest.php`<br>`denticore-api/tests/Feature/Odontogram/CorrectionTest.php` |
| CA-23.4 | 1 | `denticore-api/tests/Feature/Odontogram/CorrectionTest.php` |
| CA-35.1 | 2 | `denticore-api/tests/Feature/Treatment/IssueBudgetTest.php`<br>`denticore-api/tests/Unit/Treatment/BudgetCalculatorTest.php` |
| CA-35.2 | 1 | `denticore-api/tests/Feature/Treatment/IssueBudgetTest.php` |
| CA-35.3 | 1 | `denticore-api/tests/Feature/Treatment/IssueBudgetTest.php` |
| CA-35.4 | 1 | `denticore-api/tests/Feature/Treatment/IssueBudgetTest.php` |
| CA-35.5 | 1 | `denticore-api/tests/Feature/Treatment/IssueBudgetTest.php` |
| CA-35.6 | 1 | `denticore-api/tests/Feature/Treatment/IssueBudgetTest.php` |
| CA-37.1 | 2 | `denticore-api/tests/Feature/Portal/BudgetDecisionTest.php`<br>`denticore-api/tests/Feature/Treatment/BudgetDecisionTest.php` |
| CA-37.2 | 1 | `denticore-api/tests/Feature/Treatment/BudgetDecisionTest.php` |
| CA-37.3 | 1 | `denticore-api/tests/Feature/Treatment/BudgetDecisionTest.php` |
| CA-37.4 | 1 | `denticore-api/tests/Feature/Treatment/BudgetDecisionTest.php` |
| CA-37.5 | 2 | `denticore-api/tests/Feature/Portal/BudgetDecisionTest.php`<br>`denticore-api/tests/Feature/Portal/PortalSubjectsTest.php` |
| CA-39.1 | 1 | `denticore-api/tests/Feature/Treatment/PerformedProcedureTest.php` |
| CA-39.2 | 2 | `denticore-api/tests/Feature/Treatment/PerformedProcedureTest.php`<br>`denticore-api/tests/Feature/Treatment/PlanTest.php` |
| CA-39.3 | 1 | `denticore-api/tests/Feature/Treatment/PerformedProcedureTest.php` |
| CA-39.4 | 1 | `denticore-api/tests/Feature/Treatment/PerformedProcedureTest.php` |
| CA-47.1 | 1 | `denticore-api/tests/Feature/Scheduling/BookAppointmentTest.php` |
| CA-47.2 | 1 | `denticore-api/tests/Feature/Scheduling/BookAppointmentTest.php` |
| CA-47.3 | 1 | `denticore-api/tests/Feature/Scheduling/BookAppointmentTest.php` |
| CA-47.4 | 1 | `denticore-api/tests/Feature/Portal/SelfBookingTest.php` |
| CA-47.5 | 2 | `denticore-api/tests/Feature/Scheduling/BookAppointmentTest.php`<br>`denticore-api/tests/Feature/Scheduling/SchedulingSchemaTest.php` |
| CA-47.6 | 1 | `denticore-api/tests/Feature/Scheduling/BookAppointmentTest.php` |
| CA-55.1 | 2 | `denticore-api/tests/Feature/Risk/PredictTest.php`<br>`denticore-api/tests/Feature/Risk/RiskSchemaTest.php` |
| CA-55.2 | 2 | `denticore-api/tests/Feature/Risk/PredictTest.php`<br>`denticore-api/tests/Feature/Risk/RiskSchemaTest.php` |
| CA-55.3 | 2 | `denticore-api/tests/Feature/Risk/PredictTest.php`<br>`denticore-spa/e2e/risk.spec.ts` |
| CA-55.4 | 1 | `denticore-api/tests/Feature/Risk/PredictTest.php` |
| CA-55.5 | 1 | `denticore-api/tests/Feature/Risk/CircuitBreakerTest.php` |
| CA-55.6 | 2 | `denticore-api/tests/Feature/Risk/PredictTest.php`<br>`denticore-ml/tests/test_predict.py` |
| CA-55.7 | 1 | `denticore-api/tests/Feature/Risk/PredictTest.php` |

## RNF de §17.4 (50)

| ID | Pruebas | Archivos o evidencia |
| --- | --: | --- |
| RNF-001 | 2 | `denticore-api/tests/Feature/Support/MoneyTest.php`<br>`denticore-api/tests/Unit/Treatment/BudgetCalculatorTest.php` |
| RNF-002 | 1 | `denticore-api/tests/Feature/Patients/LegalRepresentativeTest.php` |
| RNF-003 | 3 | `denticore-api/tests/Feature/Odontogram/ClinicalValidatorDatabaseTest.php`<br>`denticore-api/tests/Feature/Support/SqlHelperObjectsTest.php`<br>`denticore-api/tests/Unit/Odontogram/ClinicalValidatorTest.php` |
| RNF-004 | 1 | `denticore-api/tests/Feature/Odontogram/NtsCatalogSeedTest.php` |
| RNF-005 | 0 | ci: Informe de trazabilidad (scripts/traceability/e1.mjs, workflow traceability): falla si un RF, una RN o un CA de E1 no tiene prueba. |
| RNF-006 | 0 | diferido: R-03 (MS-04): el k6 de lecturas bajo PC-N (T-174) pasa a MS-10 (TASK-072 y TASK-116), en el entorno de referencia. |
| RNF-007 | 1 | `denticore-api/tests/Feature/Patients/PatientSearchTest.php` |
| RNF-008 | 0 | diferido: R-03 (MS-04): el k6 de escrituras simples bajo PC-N (T-174) pasa a MS-10 (TASK-072 y TASK-116). |
| RNF-011 | 0 | diferido: R-03 (MS-04): el k6 de recálculo y emisión de presupuestos (T-178) pasa a MS-10 (TASK-072 y TASK-116). |
| RNF-013 | 0 | diferido: R-03 (MS-04): el p95 de la predicción (T-179) pasa a MS-10. El corte de 3 s y la respuesta «no disponible» ya se prueban en T-127 (CA-55.3) y en el E2E @motor-detenido. |
| RNF-016 | 0 | diferido: R-03 (MS-04): el p95 de la reserva bajo PC-N (T-174) pasa a MS-10. La restricción de exclusión con 50 reservas simultáneas se prueba en T-176 (load/pc-e.js). |
| RNF-035 | 0 | diferido: R-03 (MS-04): la comparación PC-N contra PC-P (T-175) pasa a MS-10 (TASK-072 y TASK-116). |
| RNF-038 | 2 | `denticore-api/tests/Feature/Scheduling/BookAppointmentTest.php`<br>`load/pc-e.js` |
| RNF-046 | 2 | `denticore-api/tests/Contract/OpenApiTest.php`<br>`denticore-api/tests/Feature/Support/MoneyTest.php` |
| RNF-051 | 1 | `denticore-spa/e2e/budget-portal.spec.ts` |
| RNF-052 | 1 | `denticore-spa/src/components/TableWrap.test.jsx` |
| RNF-061 | 1 | `denticore-spa/e2e/attention.spec.ts` |
| RNF-063 | 1 | `denticore-spa/src/pages/treatment/Budget.test.jsx` |
| RNF-064 | 1 | `denticore-spa/src/api/schemas.test.js` |
| RNF-074 | 1 | `denticore-spa/e2e/risk.spec.ts` |
| RNF-079 | 1 | `denticore-api/tests/Feature/Support/IdempotencyTest.php` |
| RNF-091 | 2 | `denticore-api/tests/Feature/Support/SecurityTest.php`<br>`denticore-api/tests/Feature/Support/VersionedEncryptionTest.php` |
| RNF-093 | 2 | `denticore-api/tests/Feature/Identity/PasswordPolicyTest.php`<br>`denticore-api/tests/Feature/Support/SecurityTest.php` |
| RNF-094 | 2 | `denticore-api/tests/Feature/Support/SecurityTest.php`<br>`denticore-spa/src/auth/tokenStorage.test.js` |
| RNF-096 | 2 | `denticore-api/tests/Feature/Support/HttpContractTest.php`<br>`denticore-api/tests/Feature/Support/SecurityTest.php` |
| RNF-100 | 0 | ci y manual: gitleaks en cada cambio (api.yml, «Dependencias, secretos y SAST»), sin hallazgos. La rotación cada 90 días de las credenciales de servicio queda como condición C-02 del TCR-E1 (docs/e1/TCR-E1.md): en E1 solo hay credenciales locales y de la CI; el gestor de secretos llega con TASK-111. |
| RNF-101 | 12 | `denticore-api/tests/Architecture/TenancyTest.php`<br>`denticore-api/tests/Feature/Isolation/CrossClinicTest.php`<br>`denticore-api/tests/Feature/Isolation/PayloadTenantIdTest.php`<br>`denticore-api/tests/Feature/Isolation/PortalIsolationTest.php`<br>`denticore-api/tests/Feature/Patients/ConsentTest.php`<br>`denticore-api/tests/Feature/Patients/InformedConsentTest.php`<br>`denticore-api/tests/Feature/Scheduling/AppointmentTypeTest.php`<br>`denticore-api/tests/Feature/Scheduling/NotificationInboxTest.php`<br>`denticore-api/tests/Feature/Scheduling/WorkScheduleTest.php`<br>`denticore-api/tests/Feature/Support/FileStorageTest.php`<br>`denticore-api/tests/Feature/Support/OutboxTest.php`<br>`denticore-api/tests/Feature/Support/TenantContextTest.php` |
| RNF-104 | 1 | `denticore-spa/src/security.test.js` |
| RNF-110 | 3 | `denticore-api/tests/Feature/Scheduling/NotificationTest.php`<br>`denticore-api/tests/Feature/Support/HttpContractTest.php`<br>`denticore-api/tests/Feature/Support/LogPrivacyTest.php` |
| RNF-119 | 1 | `denticore-api/tests/Architecture/LayersTest.php` |
| RNF-121 | 1 | `denticore-api/tests/Architecture/ClinicalValidationTest.php` |
| RNF-122 | 0 | ci: Larastan nivel 6 (api.yml), ESLint (spa.yml), ruff y mypy estricto (ml.yml); todos bloquean el PR. |
| RNF-123 | 0 | ci: pint --test (api.yml), prettier --check (spa.yml) y ruff format --check (ml.yml); todos bloquean el PR. |
| RNF-126 | 0 | ci: pest --coverage --min=80 y el umbral del 95 % en los servicios críticos (scripts/coverage/critical.mjs, api.yml); Vitest con 70 % de líneas (spa.yml); pytest --cov con fail_under = 80 (ml.yml). |
| RNF-128 | 0 | ci: Informe de trazabilidad (scripts/traceability/e1.mjs): cada RN de los CUS de E1 y cada CA de §11 de esos CUS citados por al menos una prueba. |
| RNF-130 | 4 | `denticore-api/tests/Architecture/TimeTest.php`<br>`denticore-api/tests/Feature/Support/EvidenceTest.php`<br>`denticore-api/tests/Feature/Support/FileStorageTest.php`<br>`denticore-api/tests/Feature/Support/OutboxTest.php` |
| RNF-135 | 0 | manual: Todo cambio entra por PR con la CI y SonarCloud en verde, pero los PR de E1 se integraron con --admin, sin un segundo revisor, y la protección de main solo exige el check e2e. Desviación C-04 del TCR-E1, aceptada por el dueño del producto el 2026-10-17; se vuelve a decidir al iniciar E2. |
| RNF-146 | 1 | `denticore-api/tests/Feature/Patients/MedicalHistoryTest.php` |
| RNF-147 | 1 | `denticore-api/tests/Feature/Risk/PredictTest.php` |
| RNF-149 | 1 | `denticore-api/tests/Feature/Patients/MedicalHistoryTest.php` |
| RNF-151 | 2 | `denticore-api/tests/Feature/Odontogram/FindingCatalogSchemaTest.php`<br>`denticore-api/tests/Feature/Odontogram/NtsCatalogSeedTest.php` |
| RNF-153 | 1 | `denticore-api/tests/Feature/Odontogram/ClinicalRecordTest.php` |
| RNF-165 | 1 | `denticore-ml/tests/test_training.py` |
| RNF-168 | 2 | `denticore-api/tests/Contract/MlContractTest.php`<br>`denticore-ml/tests/test_shap.py` |
| RNF-169 | 1 | `denticore-ml/tests/test_determinism.py` |
| RNF-174 | 4 | `denticore-api/tests/Feature/Risk/CircuitBreakerTest.php`<br>`denticore-api/tests/Feature/Risk/RiskEngineClientTest.php`<br>`denticore-api/tests/Feature/Risk/RiskVariableTest.php`<br>`denticore-ml/tests/test_predict.py` |
| RNF-188 | 1 | `denticore-api/tests/Unit/ValidationMessagesTest.php` |
| RNF-189 | 1 | `denticore-spa/src/ui/format.test.js` |
| RNF-191 | 2 | `denticore-api/tests/Feature/Patients/PatientSearchTest.php`<br>`denticore-api/tests/Feature/Patients/RegisterPatientTest.php` |
| RNF-192 | 1 | `denticore-api/tests/Feature/Support/SchemaIntegrityTest.php` |

