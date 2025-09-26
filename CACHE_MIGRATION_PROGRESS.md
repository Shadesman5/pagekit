# Cache Migration Progress - Phase 2 & 3

## Phase 2: Core Modules Migration

### Files to migrate:
- [x] app/modules/database/src/ORM/MetadataManager.php - MIGRATED TO PSR-6
- [ ] app/modules/database/src/ORM/EntityManager.php
- [ ] app/modules/database/src/ORM/ModelTrait.php
- [ ] app/modules/config/src/ConfigManager.php
- [ ] app/modules/auth/src/Handler/DatabaseHandler.php
- [ ] app/modules/session/src/Handler/DatabaseSessionHandler.php
- [ ] app/modules/filesystem/src/Filesystem.php

## Phase 3: System Modules Migration

### Files to migrate:
- [ ] app/system/modules/cache/src/CacheModule.php
- [ ] app/system/modules/user/src/Controller/RoleApiController.php
- [ ] app/system/modules/user/src/Controller/ProfileController.php
- [ ] app/system/modules/user/src/Controller/RegistrationController.php
- [ ] app/system/modules/user/src/Controller/UserApiController.php
- [ ] app/system/modules/user/src/Controller/ResetPasswordController.php
- [ ] app/system/modules/user/src/Event/LoginAttemptListener.php
- [ ] app/system/modules/site/src/Model/NodeModelTrait.php
- [ ] app/system/modules/site/src/Controller/NodeApiController.php
- [ ] app/system/modules/site/src/SiteModule.php
- [ ] app/system/modules/site/src/Event/PageListener.php
- [ ] app/system/modules/widget/src/Controller/WidgetApiController.php
- [ ] app/system/modules/finder/src/Controller/FinderController.php
- [ ] app/system/src/Model/NodeTrait.php
- [ ] app/system/src/Controller/AdminController.php
- [ ] app/installer/src/Package/PackageManager.php

## Test Files (Skip - not production):
- app/modules/filesystem/src/Tests/FilesystemTest.php
- app/modules/session/src/Tests/SessionTest.php
- app/modules/filesystem/src/Tests/Adapter/StreamAdapterTest.php
- app/modules/config/src/Tests/ConfigManagerTest.php
- app/system/modules/cache/src/Tests/Psr6AdapterTest.php

## Already using PSR-6:
- app/system/modules/cache/src/Adapter/Psr6Adapter.php