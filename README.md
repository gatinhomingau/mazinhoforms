# Cliente Fiel — Firebase Hosting

Versão estática do formulário **Cliente Fiel - Sorteio Mazinho Solidário**. A pasta publicada não usa PHP:

- Firebase Hosting: HTML, CSS e JavaScript
- Cloud Firestore: campanhas e cadastros
- Firebase Authentication: acesso ao painel
- Exportação TXT: gerada no navegador

## Configurar o projeto

1. Crie um projeto em <https://console.firebase.google.com/>.
2. Em **Firestore Database**, crie o banco.
3. Em **Authentication > Sign-in method**, habilite **E-mail/senha**.
4. Em **Authentication > Users**, crie manualmente o usuário administrador.
5. Não é necessário copiar chaves ou editar arquivo de configuração: o próprio Firebase Hosting informa a configuração ao site automaticamente.

## Vincular e publicar

```bash
npm install -g firebase-tools
firebase login
firebase use --add
firebase deploy --only hosting,firestore:rules
```

Endereços depois da publicação:

- Formulário: `https://mazinho-forms.web.app/`
- Painel: `https://mazinho-forms.web.app/admin`

No painel, entre com o usuário criado no Authentication, crie um dia, edite as informações e clique em **Abrir cadastros**.

## Estrutura

- `public/index.html`: formulário mobile
- `public/admin.html`: painel web e mobile
- `public/public-app.js`: cadastro no Firestore
- `public/admin-app.js`: campanhas, painel e TXT
- `firestore.rules`: segurança dos dados
- `firebase.json`: configuração do deploy

Os arquivos PHP e o banco SQLite antigos estão fora de `public` e não são publicados pelo Firebase Hosting.
