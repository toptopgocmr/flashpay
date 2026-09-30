import 'package:flutter/material.dart';
import '../../models/transaction.dart';
import '../../services/transaction_service.dart';
import '../../widgets/transaction_tile.dart';
import 'transaction_detail_screen.dart';
import '../../l10n/l10n.dart';

class TransactionHistoryScreen extends StatefulWidget {
  const TransactionHistoryScreen({super.key});

  @override
  State<TransactionHistoryScreen> createState() => _TransactionHistoryScreenState();
}

class _TransactionHistoryScreenState extends State<TransactionHistoryScreen> {
  final _txService = TransactionService();
  final List<FpTransaction> _items = [];
  int _page = 1;
  bool _loading = true;
  bool _hasMore = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    final items = await _txService.history(page: _page);
    setState(() {
      _items.addAll(items);
      _hasMore = items.isNotEmpty;
      _loading = false;
    });
  }

  Future<void> _loadMore() async {
    _page++;
    await _load();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(tr('Historique des transactions'))),
      body: RefreshIndicator(
        onRefresh: () async {
          setState(() {
            _items.clear();
            _page = 1;
          });
          await _load();
        },
        child: ListView.builder(
          itemCount: _items.length + 1,
          itemBuilder: (context, index) {
            if (index == _items.length) {
              if (_loading) return Padding(padding: EdgeInsets.all(24), child: Center(child: CircularProgressIndicator()));
              if (!_hasMore) return SizedBox.shrink();
              return Padding(
                padding: EdgeInsets.all(16),
                child: Center(child: TextButton(onPressed: _loadMore, child: Text(tr('Charger plus')))),
              );
            }
            final t = _items[index];
            return TransactionTile(
              transaction: t,
              onTap: () =>
                  Navigator.push(context, MaterialPageRoute(builder: (_) => TransactionDetailScreen(transactionId: t.id))),
            );
          },
        ),
      ),
    );
  }
}
