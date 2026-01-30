import React, { useEffect, useState, useCallback } from 'react';

interface Tag {
  id: number;
  name: string;
}

interface Transaction {
  id: number;
  description: string;
  amount: number;
  type: string;
  category_rel: {
    id: number;
    name: string;
  };
  tags: Tag[];
  date: string;
  user_id: number;
}

interface GridResponse {
  items: Transaction[];
  total: number;
  page: number;
  size: number;
  total_pages: number;
}

type SortColumn = 'date' | 'description' | 'amount' | 'type' | 'category_name';
type SortOrder = 'asc' | 'desc';

interface SortIconProps {
  column: SortColumn;
  sortBy: SortColumn;
  sortOrder: SortOrder;
}

const SortIcon: React.FC<SortIconProps> = ({ column, sortBy, sortOrder }) => {
  if (sortBy !== column) {
    return <span className="ml-1 text-gray-300">↕</span>;
  }
  return <span className="ml-1">{sortOrder === 'asc' ? '↑' : '↓'}</span>;
};

interface SortableHeaderProps {
  column: SortColumn;
  children: React.ReactNode;
  className?: string;
  sortBy: SortColumn;
  sortOrder: SortOrder;
  onSort: (column: SortColumn) => void;
}

const SortableHeader: React.FC<SortableHeaderProps> = ({
  column,
  children,
  className = '',
  sortBy,
  sortOrder,
  onSort,
}) => (
  <th
    scope="col"
    className={`px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100 select-none ${className}`}
    onClick={() => onSort(column)}
  >
    <div className="flex items-center justify-center">
      {children}
      <SortIcon column={column} sortBy={sortBy} sortOrder={sortOrder} />
    </div>
  </th>
);

const TransactionGrid: React.FC = () => {
  const [data, setData] = useState<GridResponse | null>(null);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string | null>(null);
  const [page, setPage] = useState<number>(1);
  const [sortBy, setSortBy] = useState<SortColumn>('date');
  const [sortOrder, setSortOrder] = useState<SortOrder>('desc');
  const size = 10;

  const fetchData = useCallback(async () => {
    try {
      setLoading(true);
      const backendUrl = import.meta.env.VITE_BACKEND_URL;

      const params = new URLSearchParams({
        page: page.toString(),
        size: size.toString(),
        sort_by: sortBy,
        sort_order: sortOrder,
      });

      const response = await fetch(`${backendUrl}/api/v1/transactions/grid?${params}`);

      if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
      }

      const result: GridResponse = await response.json();
      setData(result);
    } catch (e: unknown) {
      setError(e instanceof Error ? e.message : 'An error occurred');
    } finally {
      setLoading(false);
    }
  }, [page, sortBy, sortOrder]);

  useEffect(() => {
    fetchData();
  }, [fetchData]);

  const handleSort = (column: SortColumn) => {
    if (sortBy === column) {
      setSortOrder(sortOrder === 'asc' ? 'desc' : 'asc');
    } else {
      setSortBy(column);
      setSortOrder('desc');
    }
    setPage(1);
  };

  const handlePreviousPage = () => {
    if (page > 1) {
      setPage(page - 1);
    }
  };

  const handleNextPage = () => {
    if (data && page < data.total_pages) {
      setPage(page + 1);
    }
  };

  if (loading && !data) {
    return <div className="text-gray-700">Loading transactions...</div>;
  }

  if (error) {
    return <div className="text-red-500">Error: {error}</div>;
  }

  if (!data) {
    return null;
  }

  return (
    <div className="bg-white shadow overflow-hidden sm:rounded-lg mt-8">
      <div className="px-4 py-5 sm:px-6 flex justify-between items-center">
        <h3 className="text-lg leading-6 font-medium text-gray-900">Transaction Grid</h3>
        <span className="text-sm text-gray-500">{data.total} transactions</span>
      </div>
      <div className="border-t border-gray-200">
        <table className="min-w-full divide-y divide-gray-200">
          <thead className="bg-gray-50">
            <tr>
              <SortableHeader column="date" className="w-[12%]" sortBy={sortBy} sortOrder={sortOrder} onSort={handleSort}>Date</SortableHeader>
              <SortableHeader column="description" className="w-[25%]" sortBy={sortBy} sortOrder={sortOrder} onSort={handleSort}>Description</SortableHeader>
              <SortableHeader column="amount" className="w-[12%]" sortBy={sortBy} sortOrder={sortOrder} onSort={handleSort}>Amount</SortableHeader>
              <SortableHeader column="category_name" className="w-[15%]" sortBy={sortBy} sortOrder={sortOrder} onSort={handleSort}>Category</SortableHeader>
              <th scope="col" className="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider w-[36%]">
                Tags
              </th>
            </tr>
          </thead>
          <tbody className="bg-white divide-y divide-gray-200">
            {data.items.map((transaction, index) => (
              <tr key={transaction.id} className={`${index % 2 === 0 ? 'bg-gray-50' : 'bg-white'}`}>
                <td className="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-500">
                  {new Date(transaction.date).toLocaleDateString()}
                </td>
                <td className="px-6 py-4 whitespace-nowrap text-left text-sm font-medium text-gray-900">
                  {transaction.description}
                </td>
                <td className="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-900">
                  <span className={transaction.type === 'credit' ? 'text-green-600' : 'text-red-600'}>
                    {transaction.type === 'credit' ? '+' : '-'}${transaction.amount.toFixed(2)}
                  </span>
                </td>
                <td className="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-500">
                  {transaction.category_rel.name}
                </td>
                <td className="px-6 py-4 whitespace-nowrap text-center">
                  <div className="flex flex-wrap gap-1 justify-center">
                    {transaction.tags.map((tag) => (
                      <span
                        key={tag.id}
                        className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800"
                      >
                        {tag.name}
                      </span>
                    ))}
                  </div>
                </td>
              </tr>
            ))}
            {data.items.length === 0 && (
              <tr>
                <td colSpan={5} className="px-6 py-4 text-center text-gray-500">No transactions found.</td>
              </tr>
            )}
          </tbody>
        </table>
      </div>

      {data.total_pages > 1 && (
        <div className="px-4 py-3 bg-gray-50 border-t border-gray-200 sm:px-6 flex items-center justify-between">
          <div className="text-sm text-gray-700">
            Page {data.page} of {data.total_pages}
          </div>
          <div className="flex gap-2">
            <button
              type="button"
              onClick={handlePreviousPage}
              disabled={page === 1}
              className={`px-3 py-1 rounded text-sm font-medium ${
                page === 1
                  ? 'bg-gray-200 text-gray-400 cursor-not-allowed'
                  : 'bg-blue-500 text-white hover:bg-blue-600'
              }`}
            >
              Previous
            </button>
            <button
              type="button"
              onClick={handleNextPage}
              disabled={page >= data.total_pages}
              className={`px-3 py-1 rounded text-sm font-medium ${
                page >= data.total_pages
                  ? 'bg-gray-200 text-gray-400 cursor-not-allowed'
                  : 'bg-blue-500 text-white hover:bg-blue-600'
              }`}
            >
              Next
            </button>
          </div>
        </div>
      )}
    </div>
  );
};

export default React.memo(TransactionGrid);
