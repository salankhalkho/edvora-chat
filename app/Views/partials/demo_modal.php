<!-- DEMO MODAL -->
<div id="demo-modal" role="dialog" aria-modal="true" aria-labelledby="modal-title">
  <div class="glass rounded-3xl p-8 w-full max-w-[480px] relative">
    <button onclick="closeDemo()" class="absolute top-5 right-5 p-2 rounded-lg" aria-label="Close"><svg width="16" height="16" viewBox="0 0 16 16" fill="none"><line x1="1" y1="1" x2="15" y2="15" stroke="#063D3B" stroke-width="1.8" stroke-linecap="round"/><line x1="15" y1="1" x2="1" y2="15" stroke="#063D3B" stroke-width="1.8" stroke-linecap="round"/></svg></button>
    <div class="flex items-center gap-3 mb-6"><div class="w-10 h-10 flex-shrink-0" style="filter: drop-shadow(0 4px 12px rgba(6,61,59,0.22))"><svg viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg" class="w-full h-full block"><rect width="40" height="40" rx="11" fill="#063D3B"/><path d="M20 6C20 13.732 13.732 20 6 20C13.732 20 20 26.268 20 34C20 26.268 26.268 20 34 20C26.268 20 20 13.732 20 6Z" fill="#C8FF63"/></svg></div><div><div id="modal-title" class="text-[16px] font-bold text-e-teal">Book a Demo</div><div class="text-[12px] text-e-muted">Personalised for your institution.</div></div></div>
    <form id="demo-form" onsubmit="submitDemo(event)" novalidate>
      <div class="space-y-4 mb-6">
        <div><label class="text-[12px] font-semibold text-e-teal block mb-1.5" for="demo-institution">Institution Name *</label><input type="text" id="demo-institution" required placeholder="e.g. National Business College" class="w-full border border-e-border rounded-xl px-4 py-3 text-[14px] outline-none transition-colors" style="background:#FAFCFB;color:#092F2E;font-family:inherit" onfocus="this.style.borderColor='#063D3B'" onblur="this.style.borderColor='#DDE9E3'"></div>
        <div><label class="text-[12px] font-semibold text-e-teal block mb-1.5" for="demo-work-email">Work Email *</label><input type="email" id="demo-work-email" required placeholder="director@yourcollege.edu" class="w-full border border-e-border rounded-xl px-4 py-3 text-[14px] outline-none transition-colors" style="background:#FAFCFB;color:#092F2E;font-family:inherit" onfocus="this.style.borderColor='#063D3B'" onblur="this.style.borderColor='#DDE9E3'"></div>
        <div><label class="text-[12px] font-semibold text-e-teal block mb-1.5" for="demo-phone">Phone Number</label><input type="tel" id="demo-phone" placeholder="+91 98765 43210" class="w-full border border-e-border rounded-xl px-4 py-3 text-[14px] outline-none transition-colors" style="background:#FAFCFB;color:#092F2E;font-family:inherit" onfocus="this.style.borderColor='#063D3B'" onblur="this.style.borderColor='#DDE9E3'"></div>
        <div><label class="text-[12px] font-semibold text-e-teal block mb-1.5" for="demo-role">Your Role</label><select id="demo-role" class="w-full border border-e-border rounded-xl px-4 py-3 text-[14px] outline-none transition-colors appearance-none" style="background:#FAFCFB;color:#092F2E;font-family:inherit"><option value="">Select your role</option><option>Admissions Director</option><option>Enrollment Director</option><option>Marketing / CMO</option><option>Digital Transformation Lead</option><option>University Leadership</option><option>IT / Digital Team</option><option>Other</option></select></div>
      </div>
      <button type="submit" class="btn-primary text-[15px] px-6 py-3.5 w-full justify-center">Request a Personalised Demo &rarr;</button>
      <p class="text-[11px] text-e-muted text-center mt-3">No sales pressure. We&#8217;ll walk you through the platform at your pace.</p>
    </form>
    <div id="demo-success" class="hidden text-center py-6">
      <div class="w-14 h-14 rounded-full flex items-center justify-center mx-auto mb-4" style="background:#E6F7D2"><svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M5 12l5 5L19 7" stroke="#063D3B" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
      <div class="text-[17px] font-bold text-e-teal mb-2">Thank You!</div>
      <p class="text-[14px] text-e-muted leading-[1.6]">We&#8217;ve received your request. Our team will reach out within one business day to schedule your personalised Edvora demo.</p>
      <button onclick="closeDemo()" class="btn-primary text-[14px] px-6 py-3 mt-6">Close</button>
    </div>
  </div>
</div>

<script>
function openDemo(){
  const m=document.getElementById('demo-modal');
  if(m){
    m.classList.add('open');
    document.body.style.overflow='hidden';
    const inst=document.getElementById('demo-institution');
    if(inst) setTimeout(()=>inst.focus(),100);
  }
}
function closeDemo(){
  const m=document.getElementById('demo-modal');
  if(m){
    m.classList.remove('open');
    document.body.style.overflow='';
  }
}
document.addEventListener('DOMContentLoaded', function() {
  const modal=document.getElementById('demo-modal');
  if(modal){
    modal.addEventListener('click',function(e){if(e.target===this)closeDemo();});
  }
});
function submitDemo(e){
  e.preventDefault();
  const inst=document.getElementById('demo-institution').value.trim();
  const email=document.getElementById('demo-work-email').value.trim();
  if(!inst||!email){alert('Please fill in your institution name and work email.');return;}
  document.getElementById('demo-form').classList.add('hidden');
  document.getElementById('demo-success').classList.remove('hidden');
}
function toggleMenu(){
  const m=document.getElementById('mobile-menu');
  if(m){
    const open=m.classList.toggle('open');
    document.body.style.overflow=open?'hidden':'';
  }
}
document.addEventListener('keydown',(e)=>{
  if(e.key==='Escape'){
    closeDemo();
    const mm=document.getElementById('mobile-menu');
    if(mm) mm.classList.remove('open');
    document.body.style.overflow='';
  }
});
</script>
