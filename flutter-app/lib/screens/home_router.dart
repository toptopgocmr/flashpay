import 'package:flutter/material.dart';
import '../models/user.dart';
import 'agent/agent_home_screen.dart';
import 'client/client_shell.dart';
import 'merchant/cashier_home_screen.dart';
import 'merchant/merchant_home_screen.dart';

/// Écran d'accueil selon le profil connecté : chaque profil a son interface et ses menus.
Widget homeFor(FpProfile? profile) => switch (profile) {
      FpProfile.merchant => MerchantHomeScreen(),
      FpProfile.agent => AgentHomeScreen(),
      FpProfile.cashier => CashierHomeScreen(),
      _ => ClientShell(),
    };
