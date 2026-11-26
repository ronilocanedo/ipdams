document.addEventListener('DOMContentLoaded',()=>{const print=document.getElementById('print-report');if(print)print.addEventListener('click',()=>window.print());});

// Embedded report controls stay in the Reports workspace.
(() => { const b=document.getElementById('embedded-print-report'); if(b)b.addEventListener('click',()=>window.print()); })();
