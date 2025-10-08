const { test, expect } = require('@playwright/test');

test('Real menucards test', async ({ page }) => {
  console.log('=== REAL TEST START ===\n');
  
  // Use the authenticated session from installation test
  await page.goto('http://localhost:8080/admin/menucards/menu');
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(2000);
  
  // Check URL
  console.log('Current URL:', page.url());
  
  // Listen to console
  const consoleLogs = [];
  page.on('console', msg => {
    const text = msg.text();
    if (text.includes('menu') || text.includes('Menu') || text.includes('ERROR') || text.includes('Error')) {
      consoleLogs.push(`[${msg.type()}] ${text}`);
    }
  });
  
  // Listen to network
  const apiCalls = [];
  page.on('response', response => {
    if (response.url().includes('/api/menucards')) {
      apiCalls.push({
        url: response.url(),
        status: response.status(),
        statusText: response.statusText()
      });
    }
  });
  
  // Reload to capture logs
  await page.reload();
  await page.waitForTimeout(3000);
  
  console.log('\n=== API CALLS ===');
  apiCalls.forEach(call => {
    console.log(`${call.status} ${call.statusText} - ${call.url}`);
  });
  
  console.log('\n=== CONSOLE LOGS ===');
  consoleLogs.forEach(log => console.log(log));
  
  // Check if Vue rendered
  const vueCheck = await page.evaluate(() => {
    const menusDiv = document.getElementById('menus');
    return {
      divExists: !!menusDiv,
      innerHTML: menusDiv ? menusDiv.innerHTML.substring(0, 200) : 'DIV NOT FOUND',
      hasH2: !!menusDiv && menusDiv.querySelector('h2') !== null
    };
  });
  
  console.log('\n=== VUE RENDERING ===');
  console.log(JSON.stringify(vueCheck, null, 2));
  
  // Screenshot
  await page.screenshot({ path: '/tmp/menucards-real-test.png', fullPage: true });
  console.log('\n✅ Screenshot: /tmp/menucards-real-test.png\n');
  
  // Try to find Add Menu button
  const hasAddButton = await page.locator('button:has-text("Add Menu")').count();
  console.log('Add Menu button count:', hasAddButton);
  
  if (hasAddButton > 0) {
    console.log('✅✅✅ EXTENSION WORKS! ✅✅✅');
  } else {
    console.log('❌ Extension UI not rendering');
  }
});
