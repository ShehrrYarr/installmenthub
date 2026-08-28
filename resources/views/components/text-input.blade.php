@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-walnut-400 dark:focus:border-walnut-600 focus:ring-walnut-400 dark:focus:ring-walnut-600 rounded-md shadow-sm']) }}>
