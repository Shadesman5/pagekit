const { test } = require('@playwright/test');

test('Debug menucards page', async ({ page }) => {
  // Login
  await page.goto('http://localhost:8080/admin/login');
  await page.fill('input[name="username"]', 'admin');
  await page.fill('input[name="password"]', 'SecurePassword123!');
  await page.click('button[type="submit"]');
  await page.waitForLoadState('networkidle');
  
  console.log('✅ Logged in');
  
  // Go to menucards/menu
  console.log('\n=== Navigating to /admin/menucards/menu ===');
  await page.goto('http://localhost:8080/admin/menucards/menu');
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(2000);
  
  const url = page.url();
  console.log('Current URL:', url);
  
  // Check page HTML
  const content = await page.content();
  console.log('\n=== Checking HTML content ===');
  console.log('Has id="menus":', content.includes('id="menus"'));
  console.log('Has <menu-list>:', content.includes('<menu-list>'));
  console.log('Has menu-index.js script:', content.includes('menu-index.js'));
  console.log('Has window.$data:', content.includes('window.$data'));
  
  // Check loaded scripts
  const scripts = await page.evaluate(() => {
    return Array.from(document.querySelectorAll('script[src]'))
      .map(s => s.src);
  });
  console.log('\n=== Loaded scripts (menucards only) ===');
  scripts.filter(s => s.includes('menucards')).forEach(s => console.log(s));
  
  // Check Vue
  const vueCheck = await page.evaluate(() => {
    return {
      hasVue: typeof Vue !== 'undefined',
      vueVersion: typeof Vue !== 'undefined' ? Vue.version : null,
      hasMenusDiv: !!document.getElementById('menus'),
      menusInnerHTML: document.getElementById('menus')?.innerHTML || 'NOT FOUND'
    };
  });
  console.log('\n=== Vue Status ===');
  console.log(JSON.stringify(vueCheck, null, 2));
  
  // Check console
  const logs = [];
  const errors = [];
  page.on('console', msg => logs.push(`[${msg.type()}] ${msg.text()}`));
  page.on('pageerror', err => errors.push(err.message));
  
  await page.reload();
  await page.waitForTimeout(2000);
  
  console.log('\n=== Console Logs ===');
  logs.slice(-10).forEach(l => console.log(l));
  
  if (errors.length > 0) {
    console.log('\n=== Errors ===');
    errors.forEach(e => console.log(e));
  }
  
  await page.screenshot({ path: '/tmp/debug-menucards.png' });
  console.log('\n✅ Screenshot: /tmp/debug-menucards.png');
});
