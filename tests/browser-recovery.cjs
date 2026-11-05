/** Focused regression for UI controls after rejected discounts and server-cart restore. */
const {chromium} = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const fixture = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const output = process.argv[3];
let checks = 0;
function check(ok, label) { assert.ok(ok, label); checks++; console.log('PASS: ' + label); }
(async () => {
 const browser = await chromium.launch({executablePath: process.env.UPOS_CHROMIUM_PATH || '/usr/bin/chromium', args: ['--no-sandbox','--disable-dev-shm-usage']});
 try {
  const context = await browser.newContext({viewport: {width:1440,height:1000}});
  await context.addCookies([{name:fixture.cookieName,value:fixture.users.manager.cookie,url:fixture.baseUrl}]);
  const p = await context.newPage(); await p.goto(fixture.baseUrl + '?upos_app=1');
  await p.locator(`[data-product-id="${fixture.products[1]}"]`).click();
  for(const [id,value] of [['first-name','مشتری'],['last-name','آزمایشی'],['phone','09123456789']]) await p.locator('#'+id).fill(value);
  await p.locator('#discount-kind').selectOption('coupon'); await p.locator('#coupon-code').fill('invalid-coupon');
  await p.locator('#prepare-sale').click(); await p.waitForFunction(() => !document.querySelector('#prepare-sale').disabled);
  check(await p.locator('#coupon-code').isEnabled() && await p.locator('#search').isEnabled(), 'rejected coupon restores discount and catalog editing controls');
  await p.locator('#discount-kind').selectOption('amount'); await p.locator('#manual-value').fill('9999.00'); await p.locator('#manual-reason').fill('اصلاح تخفیف آزمایشی');
  await p.locator('#prepare-sale').click(); await p.waitForFunction(() => !document.querySelector('#prepare-sale').disabled);
  check(await p.locator('#manual-value').isEnabled() && await p.locator('#manual-reason').isEnabled() && await p.locator('#search').isEnabled(), 'rejected manual amount restores every editing control');
  await p.locator('#manual-value').fill('5.00'); await p.locator('.parking-controls').evaluate(e=>e.open=true); await p.locator('#park-label').fill('آزمون بازیابی کنترل‌ها');
  await p.locator('#park-cart').click(); await p.waitForFunction(() => document.querySelectorAll('.cart-row').length === 0);
  await p.locator('#parked-carts').evaluate(e=>e.open=true); await p.locator('#parked-list .recent-row button').first().click(); await p.locator('.cart-row').waitFor();
  check(await p.locator('#search').isEnabled() && await p.locator('#manual-value').isEnabled(), 'restored server basket permits catalog and discount editing together');
  await p.locator('.parking-controls').evaluate(e=>e.open=false); await p.evaluate(()=>{document.querySelector('.order-pane').scrollTop=0;window.scrollTo(0,0);});
  if(output) {fs.mkdirSync(output,{recursive:true});await p.screenshot({path:path.join(output,'desktop.png'),fullPage:true});await p.setViewportSize({width:390,height:844});await p.locator('[data-tab="order"]').click();await p.evaluate(()=>window.scrollTo(0,0));await p.screenshot({path:path.join(output,'mobile.png'),fullPage:true});}
  await p.locator('#prepare-sale').click(); await p.locator('#payment-section').waitFor({state:'visible'});
  check(await p.locator('#pay-sale').isVisible(), 'corrected and restored manual discount prepares a real order successfully');
  console.log(`Completed ${checks} UI recovery browser checks.`);
 } finally {await browser.close();}
})().catch(e=>{console.error(e.stack);process.exit(1);});
