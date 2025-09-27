# E2E Test Organization & Structure

## Test Numbering System

Tests are organized with a 3-digit prefix system:

### 0xx - Installation & Setup Tests (Uninstalled State)
- **001**: Complete Installation Flow
- **002**: Installation Validation
- **009**: Installation Cleanup

### 1xx - Core Features (Basic Installed State)
- **100**: Authentication (Login/Logout)
- **110**: Dashboard
- **120**: Basic Pages
- **130**: Basic Frontend

### 2xx - Content & Media (Content Management)
- **200**: Site/Page Management
- **210**: Blog Module
- **220**: Media/File Management
- **230**: Menu System

### 3xx - System & Configuration
- **300**: User Management
- **310**: Roles & Permissions
- **320**: System Settings
- **330**: Mail Configuration
- **340**: Cache Management
- **350**: Widget System

### 4xx - Advanced Features
- **400**: Theme Management
- **410**: Extensions/Marketplace
- **420**: Multi-language
- **430**: Comments System
- **440**: Search Functionality

### 5xx - Security & Performance
- **500**: Security Tests
- **510**: Performance Tests
- **520**: API Tests
- **530**: Error Handling

### 9xx - Cleanup & Special
- **900**: Test Data Cleanup
- **999**: Debug/Development Tests

## Test Execution Order

1. **Fresh Installation**: Run 0xx tests
2. **Installed System**: Run 1xx → 2xx → 3xx → 4xx
3. **Full Suite**: Run all in numerical order
4. **Cleanup**: Run 9xx tests

## File Naming Convention

```
[number]-[feature]-[type].spec.js
```

Examples:
- `001-installation-complete.spec.js`
- `100-auth-basic.spec.js`
- `210-blog-full.spec.js`