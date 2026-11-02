// Adds Salary Register, Books (Vyapar replacement) and Database upgrade to the CRM sidebar. Each opens its own page with the same login.
(function(){ if(typeof nav==='undefined'||typeof screens==='undefined') return;
  const add=(key,icon,label,url,perm)=>{ const i=nav.findIndex(n=>n[0]==='staff'); nav.splice(i>=0?i+1:nav.length,0,[key,icon,label]);
    screens[key]=function(){ const ps=me?.perms||[]; const ok=ps.includes('*')||ps.includes(perm); if(ok) setTimeout(()=>{ location.href=url; },30);
      return `<section class="card"><div class="card-body" style="padding:30px">${ok?'Opening '+label+'… <a href="'+url+'"><b>Click here</b></a> if it does not open.':'Your role cannot open '+label+'. Ask the owner.'}</div></section>`; }; };
  add('dbupgrade','⟳','Database upgrade','upgrade.php','*');
  add('books','▤','Books (Vyapar)','books.php','read');
  add('salary','₹','Salary Register','salary.php','payroll');
})();
