# Cliente Fiel

Aplicação PHP para cadastrar clientes e regiões por vendedor, separados por dias de campanha.

## Como usar

1. Abra `http://localhost/formsmazinho/admin.php`.
2. No primeiro acesso, crie a senha administrativa.
3. Clique em **Novo dia**, informe a data e o título.
4. Selecione o dia e clique em **Abrir cadastros**.
5. Compartilhe `http://localhost/formsmazinho/` com os vendedores.
6. No painel, selecione o dia e use **Baixar TXT**.

O sistema usa SQLite e cria automaticamente o arquivo `data/cliente-fiel.sqlite`. Caso apareça um aviso sobre o driver, ative `pdo_sqlite` no PHP usado pelo WAMP.

Cada linha aceita os formatos `Nome de Região`, `Nome - Região`, `Nome | Região` ou `Nome; Região`.
