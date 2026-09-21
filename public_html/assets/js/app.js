(() => {
 const cart={}, meta=window.AQV_PRODUCTS||{};
 const brl=v=>new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'}).format(v);
 const esc=v=>String(v).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
 function rebuild(){
   document.querySelectorAll('[data-product-id]').forEach(i=>{const id=i.dataset.productId,q=Math.max(0,parseInt(i.value||'0',10));if(q)cart[id]=q;else delete cart[id]});
   const sum=document.getElementById('cart-summary'),tot=document.getElementById('cart-total'),hidden=document.getElementById('cart-json'),btn=document.getElementById('checkout-section');
   if(!sum||!tot)return;
   let total=0,html='';
   Object.entries(cart).forEach(([id,q])=>{const p=meta[id];if(!p)return;total+=p.price*q;html+=`<div class="summary-row"><span>${q}× ${esc(p.name)}</span><strong>${brl(p.price*q)}</strong></div>`});
   sum.innerHTML=html||'<p style="color:#6d8292">Seu carrinho está vazio.</p>';tot.textContent=brl(total);if(hidden)hidden.value=JSON.stringify(cart);if(btn)btn.disabled=total<=0;visitors();
 }
 function visitors(){
   const wrap=document.getElementById('visitors');if(!wrap)return;let idx=0,html='';
   Object.entries(cart).forEach(([id,q])=>{const p=meta[id];if(!p||!p.requires_visitor)return;for(let n=0;n<q;n++,idx++){
     html+=`<div class="visitor-card"><div class="visitor-head"><h3>Visitante ${idx+1}</h3><span class="pill">${esc(p.name)}</span></div>
     <input type="hidden" name="visitors[${idx}][product_id]" value="${id}"><div class="form-grid">
     <div class="form-group"><label>Nome</label><input name="visitors[${idx}][first_name]" required autocomplete="given-name"></div>
     <div class="form-group"><label>Sobrenome</label><input name="visitors[${idx}][last_name]" required autocomplete="family-name"></div>
     <div class="form-group"><label>E-mail</label><input type="email" name="visitors[${idx}][email]" required autocomplete="email"></div>
     <div class="form-group"><label>Telefone</label><input name="visitors[${idx}][phone]" required autocomplete="tel"></div>
     <div class="form-group"><label>Data de entrada</label><input type="date" min="${new Date().toISOString().slice(0,10)}" name="visitors[${idx}][entry_date]" required></div>
     <div class="form-group"><label>Sexo</label><select name="visitors[${idx}][sex]" required><option value="">Selecione</option><option value="feminino">Feminino</option><option value="masculino">Masculino</option><option value="outro">Outro</option><option value="nao_informado">Prefiro não informar</option></select></div>
     <div class="form-group"><label>Documento</label><select name="visitors[${idx}][document_type]"><option>CPF</option><option>RG</option><option>CNH</option></select></div>
     <div class="form-group"><label>Número do documento</label><input name="visitors[${idx}][document_number]" required></div>
     <div class="form-group full"><label>Foto para identificação na catraca</label><input class="photo-input" data-preview="photo-${idx}" type="file" name="photos[${idx}]" accept="image/jpeg,image/png,image/webp" capture="user" required><small>Tire a foto agora ou selecione da galeria.</small><img class="photo-preview" id="photo-${idx}" alt="Prévia"></div>
     <div class="form-group full"><label style="display:flex;gap:10px;align-items:flex-start;font-weight:650"><input style="width:auto;margin-top:4px" type="checkbox" name="visitors[${idx}][biometric_consent]" value="1" required>Autorizo o uso desta foto para identificação e controle de acesso ao AcquaVale, conforme a política de privacidade.</label></div>
     </div></div>`;
   }});
   wrap.innerHTML=html||'<div class="notice">Adicione ao menos um ingresso para cadastrar visitantes.</div>';
   document.querySelectorAll('.photo-input').forEach(i=>i.addEventListener('change',()=>{const img=document.getElementById(i.dataset.preview),f=i.files&&i.files[0];if(img&&f){img.src=URL.createObjectURL(f);img.style.display='block'}}));
 }
 document.querySelectorAll('[data-qty-action]').forEach(b=>b.addEventListener('click',()=>{const i=document.querySelector(`[data-product-id="${b.dataset.product}"]`);if(!i)return;i.value=Math.max(0,Math.min(20,parseInt(i.value||'0',10)+(b.dataset.qtyAction==='plus'?1:-1)));rebuild()}));
 document.querySelectorAll('[data-product-id]').forEach(i=>i.addEventListener('change',rebuild));
 document.getElementById('checkout-section')?.addEventListener('click',()=>document.getElementById('checkout')?.scrollIntoView({behavior:'smooth'}));
 rebuild();
})();
