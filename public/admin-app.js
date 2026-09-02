import { initializeApp } from 'https://www.gstatic.com/firebasejs/12.18.0/firebase-app.js';
import { getAuth, onAuthStateChanged, signInWithEmailAndPassword, signOut } from 'https://www.gstatic.com/firebasejs/12.18.0/firebase-auth.js';
import { getFirestore, collection, addDoc, getDocs, doc, updateDoc, writeBatch, query, where, serverTimestamp } from 'https://www.gstatic.com/firebasejs/12.18.0/firebase-firestore.js';

const $ = selector => document.querySelector(selector);
const defaults = {
  introText:'Este formulário foi criado para que os vendedores do Sorteio Mazinho Solidário enviem os nomes dos clientes que compraram durante o período da promoção.',
  deadlineText:'ESTÁ AUTORIZADO ENVIAR OS NOMES ATÉ AS 16H DO DIA DA CAMPANHA.',
  rulesText:'Para o cliente participar, ele precisa ter comprado com você no mínimo uma folha VIP, cartela ou bolão todos os dias da promoção.\n\nTodos os seus blocos da semana serão conferidos para localizar o nome do cliente.',
  contactText:'Qualquer dúvida na hora de preencher, chame o Antonio no WhatsApp (85) 99633-1479.',
  sellerNote:'Não é necessário colocar o nome do seu líder.',
  customerInstructions:'Coloque apenas um nome de cliente e sua região por linha. Você pode adicionar quantos nomes precisar. Não coloque ponto ou traço entre os nomes.'
};
let auth, db, campaigns = [], selected = null, submissions = [];
const formatDate = value => value ? value.split('-').reverse().join('/') : '';
const escapeHtml = value => String(value ?? '').replace(/[&<>"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[char]));
function message(value, type='success') {
  $('#admin-message').textContent=value; $('#admin-message').className=`alert ${type}`; $('#admin-message').hidden=false;
  setTimeout(() => $('#admin-message').hidden=true, 4000);
}
async function startAdmin() {
  try {
    const response = await fetch('/__/firebase/init.json');
    if (!response.ok) throw new Error('Configuração automática indisponível.');
    const app=initializeApp(await response.json()); auth=getAuth(app); db=getFirestore(app);
    onAuthStateChanged(auth, user => { $('#auth-screen').hidden=!!user; $('#dashboard').hidden=!user; if(user) loadCampaigns(); });
  } catch (error) {
    console.error(error);
    $('#login-error').textContent='Não foi possível conectar ao Firebase. Publique novamente e atualize a página.';
    $('#login-error').hidden=false;
  }
}
startAdmin();

$('#login-form').addEventListener('submit', async event => {
  event.preventDefault(); $('#login-error').hidden=true;
  try { await signInWithEmailAndPassword(auth,$('#email').value.trim(),$('#password').value); }
  catch(error) { console.error(error); $('#login-error').textContent='E-mail ou senha incorretos.'; $('#login-error').hidden=false; }
});
$('#logout').addEventListener('click',()=>signOut(auth));

async function loadCampaigns(preferredId=null) {
  const snapshot=await getDocs(collection(db,'campaigns'));
  campaigns=snapshot.docs.map(item=>({id:item.id,...item.data()})).sort((a,b)=>(b.campaignDate||'').localeCompare(a.campaignDate||''));
  if(!campaigns.length){$('#campaign-list').innerHTML='';$('#empty-campaigns').hidden=false;$('#campaign-panel').hidden=true;return;}
  $('#empty-campaigns').hidden=true;
  await selectCampaign(preferredId || selected?.id || campaigns[0].id);
}
function renderList() {
  $('#campaign-list').innerHTML=campaigns.map(item=>`<button class="campaign-chip ${selected?.id===item.id?'selected':''}" data-id="${item.id}"><span>${formatDate(item.campaignDate)}${item.isActive?' · ABERTO':''}</span><strong>${escapeHtml(item.title)}</strong></button>`).join('');
  document.querySelectorAll('.campaign-chip').forEach(button=>button.addEventListener('click',()=>selectCampaign(button.dataset.id)));
}
async function selectCampaign(id) {
  selected=campaigns.find(item=>item.id===id)||campaigns[0]; renderList(); $('#campaign-panel').hidden=false;
  $('#selected-title').textContent=selected.title; $('#selected-date').textContent=formatDate(selected.campaignDate);
  $('#toggle-campaign').textContent=selected.isActive?'Fechar cadastros':'Abrir cadastros';
  const fields={'#edit-title':selected.title,'#edit-date':selected.campaignDate,'#purchase-start':selected.purchaseStart,'#purchase-end':selected.purchaseEnd,'#intro':selected.introText||defaults.introText,'#deadline':selected.deadlineText||defaults.deadlineText,'#rules':selected.rulesText||defaults.rulesText,'#contact':selected.contactText||defaults.contactText,'#seller-note-edit':selected.sellerNote||defaults.sellerNote,'#instructions':selected.customerInstructions||defaults.customerInstructions};
  Object.entries(fields).forEach(([key,value])=>$(key).value=value||''); await loadSubmissions();
}
async function loadSubmissions() {
  const snapshot=await getDocs(query(collection(db,'submissions'),where('campaignId','==',selected.id)));
  submissions=snapshot.docs.map(item=>({id:item.id,...item.data()})).sort((a,b)=>(b.createdAt?.seconds||0)-(a.createdAt?.seconds||0));
  $('#customers-total').textContent=submissions.reduce((sum,item)=>sum+(item.customers?.length||0),0);
  $('#sellers-total').textContent=new Set(submissions.map(item=>(item.sellerName||'').toLocaleLowerCase())).size;
  $('#submissions-total').textContent=submissions.length;
  $('#submissions-body').innerHTML=submissions.length?submissions.map(item=>`<tr><td><strong>${escapeHtml(item.sellerName)}</strong></td><td>${item.customers?.length||0}</td><td>${item.createdAt?.toDate?item.createdAt.toDate().toLocaleString('pt-BR'):'Processando'}</td></tr>`).join(''):'<tr><td colspan="3" class="empty-cell">Ainda não há envios neste dia.</td></tr>';
}
$('#new-campaign').addEventListener('click',()=>{$('#new-date').value=new Date().toISOString().slice(0,10);$('#campaign-dialog').showModal();});
$('.dialog-close').addEventListener('click',()=>$('#campaign-dialog').close());
$('#create-form').addEventListener('submit',async event=>{
  event.preventDefault();
  try { const created=await addDoc(collection(db,'campaigns'),{title:$('#new-title').value.trim(),campaignDate:$('#new-date').value,purchaseStart:'',purchaseEnd:'',isActive:false,...defaults,createdAt:serverTimestamp()});$('#campaign-dialog').close();await loadCampaigns(created.id);message('Novo dia criado. Configure e abra os cadastros.'); }
  catch(error){console.error(error);message('Não foi possível criar a campanha.','error');}
});
$('#settings-form').addEventListener('submit',async event=>{
  event.preventDefault(); const data={title:$('#edit-title').value.trim(),campaignDate:$('#edit-date').value,purchaseStart:$('#purchase-start').value,purchaseEnd:$('#purchase-end').value,introText:$('#intro').value.trim(),deadlineText:$('#deadline').value.trim(),rulesText:$('#rules').value.trim(),contactText:$('#contact').value.trim(),sellerNote:$('#seller-note-edit').value.trim(),customerInstructions:$('#instructions').value.trim()};
  try { await updateDoc(doc(db,'campaigns',selected.id),data);await loadCampaigns(selected.id);message('Data e informações atualizadas.'); }
  catch(error){console.error(error);message('Não foi possível salvar.','error');}
});
$('#toggle-campaign').addEventListener('click',async()=>{
  const opening=!selected.isActive;
  try { if(!opening) await updateDoc(doc(db,'campaigns',selected.id),{isActive:false}); else {const batch=writeBatch(db);campaigns.forEach(item=>batch.update(doc(db,'campaigns',item.id),{isActive:item.id===selected.id}));await batch.commit();} await loadCampaigns(selected.id);message(opening?'Cadastros abertos no formulário.':'Cadastros fechados.'); }
  catch(error){console.error(error);message('Não foi possível alterar o status.','error');}
});
$('#download-txt').addEventListener('click',()=>{
  const lines=[];submissions.slice().reverse().forEach(item=>(item.customers||[]).forEach(customer=>lines.push(`Vendedor: ${item.sellerName}`,`Cliente: ${customer.name}`,`Região: ${customer.region}`,'')));
  const url=URL.createObjectURL(new Blob(['\ufeff'+lines.join('\r\n')],{type:'text/plain;charset=utf-8'}));const link=document.createElement('a');link.href=url;link.download=`cliente-fiel-${selected.campaignDate}.txt`;link.click();URL.revokeObjectURL(url);
});
