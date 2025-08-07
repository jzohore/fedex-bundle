# 🤝 Contribuer à FedexBundle

Merci d’envisager une contribution à **FedexBundle** ! 🚀  
Ce guide vous aidera à comprendre comment proposer des améliorations, corriger des bugs ou ajouter des fonctionnalités.

---

## 📦 Pré-requis

Avant toute contribution, veuillez :

- Utiliser **PHP 8.1+**
- Avoir un environnement Symfony installé
- Exécuter les outils de qualité de code :
  ```bash
  composer run ci
  ```

---

## 🛠️ Installer le bundle en local

```bash
git clone https://github.com/junior-dev/fedex-bundle.git
cd fedex-bundle
composer install
```

---

## ✅ Checklist avant une PR

Merci de vous assurer que :

- [ ] Le code respecte le standard PSR-12
- [ ] Les tests passent (`phpunit`)
- [ ] Le code est typé et clair
- [ ] Les services sont correctement injectés (no hardcoding)
- [ ] Vous avez documenté toute nouvelle fonctionnalité

---

## 💡 Suggestions de contributions

- Implémenter la **création d’envois**
- Ajouter le **téléchargement d’étiquettes**
- Améliorer le **mapping des réponses**
- Ajouter des **tests unitaires et fonctionnels**
- Rendre le bundle **compatible Symfony Flex**

---

## 🧪 Tester votre contribution

```bash
composer run ci
```

Ou manuellement :

```bash
./vendor/bin/php-cs-fixer fix --dry-run
./vendor/bin/phpstan analyse
./vendor/bin/phpunit
```

---

## 📬 Faire une pull request

1. Forkez le dépôt
2. Créez une branche dédiée : `feat/shipment-creation`
3. Faites votre PR sur la branche `main`
4. Soyez clair dans la description : _"Ajout du support pour créer un envoi via l’API REST"_

---

## 🙏 Merci

Votre temps, votre contribution et vos retours sont **précieux**.  
Merci de faire de ce projet une meilleure ressource pour la communauté Symfony.

---

*— Zohoré Junior*