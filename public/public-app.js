import { initializeApp } from 'https://www.gstatic.com/firebasejs/12.18.0/firebase-app.js';
import { getFirestore, collection, query, where, limit, getDocs, addDoc, serverTimestamp } from 'https://www.gstatic.com/firebasejs/12.18.0/firebase-firestore.js';

const $ = selector => document.querySelector(selector);
const defaults = {
  introText: 'Este formulário foi criado para que os vendedores do Sorteio Mazinho Solidário enviem os nomes dos clientes que compraram durante o período da promoção.',
  deadlineText: 'ESTÁ AUTORIZADO ENVIAR OS NOMES ATÉ AS 16H DO DIA DA CAMPANHA.',
  rulesText: 'Para o cliente participar, ele precisa ter comprado com você no mínimo uma folha VIP, cartela ou bolão todos os dias da promoção.\n\nTodos os seus blocos da semana serão conferidos para localizar o nome do cliente.',
  contactText: 'Qualquer dúvida na hora de preencher, chame o Antonio no WhatsApp (85) 99633-1479.',
  sellerNote: 'Não é necessário colocar o nome do seu líder.',
  customerInstructions: 'Coloque apenas um nome de cliente e sua região por linha. Você pode adicionar quantos nomes precisar.\n\nNão coloque ponto ou traço entre os nomes. Evite enviar o formulário várias vezes.'
};
let db, campaign;

function showOnly(id) {
  ['loading','setup-error','closed','form-screen','success-screen'].forEach(name => document.getElementById(name).hidden = name !== id);
}
function formatDate(value) { return value ? value.split('-').reverse().join('/') : ''; }
function setText(id, value) { document.getElementById(id).textContent = value || ''; }
function parseCustomers(raw) {
  return raw.split(/\r?\n/).map(line => line.trim().replace(/\s+/g, ' ')).filter(Boolean).map(line => {
    let match = line.match(/^(.+)\s+(de|da|do|dos|das)\s+(.+)$/i);
    if (match) return { name: match[1].trim(), region: `${match[2]} ${match[3]}`.trim() };
    match = line.match(/^(.+?)\s*(?:\||;|\s+-\s+)\s*(.+)$/);
    return match ? { name: match[1].trim(), region: match[2].trim() } : { name: line, region: 'Não informada' };
  });
}
function renderCampaign() {
  const data = campaign.data;
  setText('campaign-title', data.title);
  setText('campaign-date', formatDate(data.campaignDate));
  ['introText','deadlineText','rulesText','contactText','sellerNote','customerInstructions'].forEach(field => {
    const elementId = field.replace(/[A-Z]/g, letter => '-' + letter.toLowerCase());
    setText(elementId, data[field] || defaults[field]);
  });
  if (data.purchaseStart && data.purchaseEnd) {
    $('#period-line').hidden = false;
    $('#period-line span').textContent = `${formatDate(data.purchaseStart)} a ${formatDate(data.purchaseEnd)}`;
  }
  showOnly('form-screen');
}
async function start() {
  try {
    const response = await fetch('/__/firebase/init.json');
    if (!response.ok) throw new Error('Configuração automática indisponível.');
    db = getFirestore(initializeApp(await response.json()));
    const snapshot = await getDocs(query(collection(db, 'campaigns'), where('isActive', '==', true), limit(1)));
    if (snapshot.empty) return showOnly('closed');
    campaign = { id: snapshot.docs[0].id, data: snapshot.docs[0].data() };
    renderCampaign();
  } catch (error) {
    console.error(error);
    $('#setup-error p').textContent = 'Não foi possível carregar o formulário. Verifique a configuração e tente novamente.';
    showOnly('setup-error');
  }
}
$('#customers').addEventListener('input', () => {
  const customers = parseCustomers($('#customers').value);
  setText('line-count', `${customers.length} ${customers.length === 1 ? 'cliente' : 'clientes'}`);
  $('#preview').hidden = !customers.length;
  $('#preview').textContent = customers.length ? `✓ ${customers.length} cliente(s) identificado(s).` : '';
});
$('#registration-form').addEventListener('submit', async event => {
  event.preventDefault();
  const sellerName = $('#seller-name').value.trim().replace(/\s+/g, ' ');
  const rawText = $('#customers').value.trim();
  const customers = parseCustomers(rawText);
  const errors = [];
  if (sellerName.length < 2) errors.push('Informe o nome do vendedor.');
  if (!$('#rules-accepted').checked) errors.push('Confirme que está ciente das regras.');
  if (!$('#contact-accepted').checked) errors.push('Confirme que leu a informação de contato.');
  if (!customers.length) errors.push('Informe pelo menos um cliente.');
  if (customers.length > 300) errors.push('Envie no máximo 300 clientes por vez.');
  if (errors.length) {
    $('#form-error').textContent = errors.join(' '); $('#form-error').hidden = false;
    return $('#form-error').scrollIntoView({ behavior:'smooth', block:'center' });
  }
  const button = $('#submit-button'); button.disabled = true; button.textContent = 'Enviando...'; $('#form-error').hidden = true;
  try {
    await addDoc(collection(db, 'submissions'), { campaignId:campaign.id, sellerName, rawText, customers, createdAt:serverTimestamp() });
    setText('success-name', sellerName);
    setText('success-count', `${customers.length} ${customers.length === 1 ? 'cliente' : 'clientes'}`);
    event.target.reset(); setText('line-count', '0 clientes'); showOnly('success-screen'); window.scrollTo(0,0);
  } catch (error) {
    console.error(error); $('#form-error').textContent = 'Não foi possível enviar. Confira sua internet e tente novamente.'; $('#form-error').hidden = false;
  } finally { button.disabled = false; button.textContent = 'Enviar cadastro'; }
});
$('#send-more').addEventListener('click', () => showOnly('form-screen'));
start();
