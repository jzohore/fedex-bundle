# 📨 FedexBundle

**FedexBundle** est un bundle Symfony permettant de communiquer avec l'API REST de FedEx : authentification OAuth2, suivi de colis, et prochainement création d'envois, tarification, et étiquettes d’expédition.

---

## 🚀 Fonctionnalités

- ✅ Authentification OAuth2 avec cache du token
- 📦 Suivi de colis par numéro de tracking
- 🛠️ Extensible : création d’envois, labels, tarifs (bientôt)
- 🧪 Compatible Symfony 6 & 7
- 🧰 Services typés, Developer Experience soignée

---

## 🧰 Installation

```bash
composer require junior-dev/fedex-bundle
```

---

## ⚙️ Configuration

### 🛠️ config/packages/fedex.yaml

```yaml
fedex:
    api_key: '%env(FEDEX_CLIENT_ID)%'
    api_password: '%env(FEDEX_CLIENT_SECRET)%'
    account_number: '%env(FEDEX_ACCOUNT_NUMBER)%'
    meter_number: '%env(FEDEX_METER_NUMBER)%'
    mode: 'test' # ou 'production'
```

### 🗝️ .env

```dotenv
FEDEX_CLIENT_ID=your_client_id
FEDEX_CLIENT_SECRET=your_client_secret
FEDEX_ACCOUNT_NUMBER=your_account_number
FEDEX_METER_NUMBER=your_meter_number

FEDEX_CLIENT_AUTH_LINK=https://apis-sandbox.fedex.com/oauth/token
FEDEX_API_TRACKING=https://apis-sandbox.fedex.com/track/v1/trackingnumbers
```

---

## 🧱 Roadmap

- [x] Authentification OAuth2
- [ ] Suivi de colis
- [ ] Création d'envois
- [ ] Téléchargement d'étiquettes
- [ ] Estimation des tarifs
- [ ] Annulation d'envois
- [ ] Workflow Github
- [ ] Écriture des tests

---

## 📜 Licence

MIT © [Zohoré Junior](mailto:zohorejuniorpro@gmail.com)

---

## 🤝 Contribuer

Les contributions sont bienvenues ! Ouvre une issue ou une pull request 🚀



![Symfony](https://img.shields.io/badge/Made%20with-Symfony-000000?logo=symfony&style=for-the-badge)