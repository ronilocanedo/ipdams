'use strict';
const {defineConfig,devices}=require('@playwright/test');
if(process.env.PLAYWRIGHT_BASE_URL!=='http://web' || process.env.IPDAMS_QA_V0380_ISOLATED!=='1') {
  throw Error('BLOCKED: PR UI Playwright must run only against the isolated v0380 synthetic Docker network');
}
module.exports=defineConfig({
  testDir:'./tests',
  testMatch:['pr-uacs-v0380.spec.cjs'],
  timeout:55_000,
  expect:{timeout:12_000},
  retries:0,
  workers:1,
  fullyParallel:false,
  reporter:[['list'],['html',{outputFolder:'/work/playwright-report',open:'never'}],['json',{outputFile:'/work/test-results/results.json'}]],
  outputDir:'/work/test-results',
  use:{baseURL:'http://web',trace:'off',screenshot:'only-on-failure',video:'off'},
  projects:[
    {name:'chromium-desktop',use:{browserName:'chromium',viewport:{width:1366,height:768}}},
    {name:'chromium-mobile',use:{...devices['Pixel 7'],browserName:'chromium'}},
  ],
});
