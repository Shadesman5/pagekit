const { test, expect } = require('@playwright/test');
const testConfig = require('../helpers/test-config');

test('Simple menucards test - with login', async ({ page }) => {
  // Login first
  await page.goto(testConfig.getAdminUrl() + '/login');
  await page.fill('input[name="username"]', 'admin');
  await page.fill('input[name="password"]', 'SecurePassword123!');
  await page.click('button[type="submit"]');
  await page.waitForLoadState('networkidle');
  console.log('✅ Logged in');
  
  // Now go to menucards
  console.log('Going to menucards/menu...');
  await page.goto('http://localhost:8080/admin/menucards/menu');
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(2000);
  
  console.log('Current URL:', page.url());
  
  // Check for scripts
  const scripts = await page.evaluate(() => {
    return Array.from(document.querySelectorAll('script[src]'))
      .map(s => s.src)
      .filter(s => s.includes('menucards') || s.includes('menu'));
  });
  console.log('Menucards scripts loaded:', scripts);
  
  // Check HTML
  const html = await page.content();
  console.log('Has id="menus":', html.includes('id="menus"'));
  console.log('Has menu-list-simple:', html.includes('menu-list-simple'));
  
  // Check if Vue rendered
  const menusDiv = await page.locator('#menus').innerHTML();
  console.log('Menus div innerHTML:', menusDiv.substring(0, 200));
  
  // Screenshot
  await page.screenshot({ path: '/tmp/menucards-logged-in.png', fullPage: true });
  console.log('Screenshot saved to /tmp/menucards-logged-in.png');
  
  // Wait to see console
  await page.waitForTimeout(2000);
});
