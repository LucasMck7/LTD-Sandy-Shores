const { SlashCommandBuilder, PermissionFlagsBits } = require('discord.js');
const { apiGet, apiPost } = require('../utils/api');
const { getStations } = require('../utils/api');
const { sendOrUpdateStockMessage } = require('./stock');

module.exports = {
  data: new SlashCommandBuilder()
    .setName('test')
    .setDescription('Commandes de test pour le Secrétaire (admin seulement)')
    .setDefaultMemberPermissions(PermissionFlagsBits.Administrator)
    .addSubcommand(sub => sub
      .setName('stock')
      .setDescription('Envoyer/mettre à jour le message de suivi des stations'))
    .addSubcommand(sub => sub
      .setName('commande_particulier')
      .setDescription('Simuler une nouvelle commande particulier'))
    .addSubcommand(sub => sub
      .setName('commande_entreprise')
      .setDescription('Simuler une nouvelle commande entreprise'))
    .addSubcommand(sub => sub
      .setName('redistrib')
      .setDescription('Simuler une redistribution')
      .addIntegerOption(opt => opt.setName('numero').setDescription('Numéro de redistribution').setRequired(true))
      .addIntegerOption(opt => opt.setName('montant').setDescription('Montant en $').setRequired(true)))
    .addSubcommand(sub => sub
      .setName('statut')
      .setDescription('Vérifier le statut du Secrétaire et de la connexion API')),

  async execute(interaction) {
    await interaction.deferReply({ ephemeral: true });
    const sub = interaction.options.getSubcommand();

    switch(sub) {

      case 'stock': {
        const { getChannel } = require('../utils/config');
        const chId = await getChannel('CHANNEL_STATIONS_PUBLIC') || interaction.channelId;
        await sendOrUpdateStockMessage(interaction.client, chId);
        await interaction.editReply(`✅ Message /stock envoyé/mis à jour dans <#${chId}>`);
        break;
      }

      case 'commande_particulier': {
        const fakeOrder = {
          ref: 'TEST-'+Date.now().toString().slice(-6),
          prenom:'Jean', nom:'Test', tel:'0612345678',
          adresse:'123 Rue du Test, Sandy Shores',
          date: new Date().toISOString().split('T')[0],
          heure:'18:00 — 19:00',
          items:[{name:'Eau purifiée',emoji:'💧',qty:100,price:1.5},{name:'Pain à burger',emoji:'🍞',qty:50,price:2.0}],
          total:250, paiement:'Paiement à la livraison', status:'pending',
        };
        const r = await apiPost('bot_notify', {type:'new_order_particulier', order:fakeOrder}, true);
        await interaction.editReply(`✅ Commande particulier simulée !\nRéf: **${fakeOrder.ref}**\nBot: ${JSON.stringify(r.bot||r)}`);
        break;
      }

      case 'commande_entreprise': {
        const fakeOrder = {
          id: 'TEST-'+Date.now().toString().slice(-6),
          company: 'Yellow Jack',
          contact:'Calista Winslow', phone:'3630957',
          deliveryDate: new Date().toISOString().split('T')[0],
          slot:'20:00-22:00',
          items:[{pid:'7',qty:200},{pid:'8',qty:100}],
          total:600, payMethod:'delivery', status:'new',
        };
        const stations = await getStations().catch(()=>[]);
        const r = await apiPost('bot_notify', {type:'new_order_entreprise', order:fakeOrder, products:[]}, true);
        await interaction.editReply(`✅ Commande entreprise simulée !\nRéf: **${fakeOrder.id}**\nBot: ${JSON.stringify(r.bot||r)}`);
        break;
      }

      case 'redistrib': {
        const num    = interaction.options.getInteger('numero');
        const montant= interaction.options.getInteger('montant');
        const fakeMsg = `💰 Montant : ${montant} $\n📋 Raison : Redistribution N°${num}`;
        await interaction.editReply(
          `📨 Simulation de redistribution N°${num} pour **${montant} $**\n\n`+
          `Envoie ce message dans ton salon factures pour tester :\n\`\`\`${fakeMsg}\`\`\``
        );
        break;
      }

      case 'statut': {
        let lines = [];
        // Test API
        try {
          const r = await apiGet('bot_get_config');
          lines.push(`✅ API site accessible`);
          lines.push(`📋 ${Object.keys(r.config?.redistribMap||{}).length} redistribution(s) configurée(s)`);
        } catch(e) { lines.push(`❌ API inaccessible : ${e.message}`); }

        // Test stations
        try {
          const stations = await getStations();
          lines.push(`⛽ ${stations.length} station(s) en BDD`);
        } catch(e) { lines.push(`❌ Stations inaccessibles : ${e.message}`); }

        // Config canaux
        const { getChannel } = require('../utils/config');
        const chPart  = await getChannel('CHANNEL_COMMANDES_PART');
        const chEnt   = await getChannel('CHANNEL_COMMANDES_ENT');
        const chStock = await getChannel('CHANNEL_STATIONS_PUBLIC');
        const chFact  = await getChannel('CHANNEL_FACTURES_ID');
        lines.push(`\n📢 **Salons configurés :**`);
        lines.push(`${chPart  ? '✅' : '❌'} Commandes particuliers : ${chPart  || 'non configuré'}`);
        lines.push(`${chEnt   ? '✅' : '❌'} Commandes entreprises : ${chEnt   || 'non configuré'}`);
        lines.push(`${chStock ? '✅' : '❌'} Suivi stations : ${chStock || 'non configuré'}`);
        lines.push(`${chFact  ? '✅' : '❌'} Salon factures : ${chFact  || 'non configuré'}`);

        await interaction.editReply(lines.join('\n'));
        break;
      }
    }
  },
};
