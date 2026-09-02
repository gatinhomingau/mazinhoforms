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
5. Em **Configurações do projeto > Seus aplicativos**, adicione um aplicativo Web.
6. Copie os valores de `firebaseConfig` para `public/firebase-config.js`.

A configuração web não é uma senha. A proteção está em `firestore.rules` e no login administrativo.

## Vincular e publicar

```bash
npm install -g firebase-tools
firebase login
firebase use --add
firebase deploy --only hosting,firestore:rules
```

Endereços depois da publicação:

- Formulário: `https://SEU_PROJECT_ID.web.app/`
- Painel: `https://SEU_PROJECT_ID.web.app/admin`

No painel, entre com o usuário criado no Authentication, crie um dia, edite as informações e clique em **Abrir cadastros**.

## Usar hospedagem estática da Hostinger

Se o destino for a Hostinger, envie **somente o conteúdo da pasta `public`** para `public_html`. O formulário continuará usando Firestore e Authentication. Adicione o domínio da Hostinger em **Firebase Authentication > Settings > Authorized domains**.

As regras devem ser publicadas uma vez:

```bash
firebase deploy --only firestore:rules
```

## Estrutura

- `public/index.html`: formulário mobile
- `public/admin.html`: painel web e mobile
- `public/public-app.js`: cadastro no Firestore
- `public/admin-app.js`: campanhas, painel e TXT
- `public/firebase-config.js`: conexão do projeto
- `firestore.rules`: segurança dos dados
- `firebase.json`: configuração do deploy

Os arquivos PHP e o banco SQLite antigos estão fora de `public` e não são publicados pelo Firebase Hosting.
